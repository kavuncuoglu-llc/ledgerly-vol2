<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Drivers\Prism\PrismTranslator;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Http\StreamTruncated;
use Generator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response as TextResponse;
use Throwable;

// Chapter 47, Topic 8: Prism as one more ChatModel. The app's request is translated in PrismTranslator, one step is run (the tool loop is the app's, so ToolGate sees every call), and what comes back is the app's normalised response. Retries, tracing, redaction, budgets and the usage log are the decorators the manager puts around every driver, so none of them is written here.
// `prism_provider` in the role's config names Prism's provider: `ollama` (its /api/chat, the default here) or any other Prism provider. Prism's `openai` provider speaks the Responses API, not /chat/completions, so it is not a drop-in for an OpenAI-compatible server.
final class PrismDriver implements ChatModel
{
    private const DEFAULT_CAPABILITIES = ['tools', 'structured', 'seed'];

    private const OLLAMA_OPTIONS = ['seed', 'top_k', 'min_p', 'stop', 'repeat_penalty', 'presence_penalty', 'frequency_penalty'];

    private array $warned = [];

    public function __construct(private array $config) {}

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability->value, $this->config['capabilities'] ?? self::DEFAULT_CAPABILITIES, true);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        try {
            $pending = $this->pending($request);

            return $this->response($request->responseFormat === null
                ? $pending->asText()
                : $pending->asStructured(), $request);
        } catch (AiRequestFailed $e) {
            throw $e;
        } catch (PrismException|RequestException $e) {
            throw $this->mapped($e);
        }
    }

    public function stream(ChatRequest $request): Generator
    {
        $content = '';
        $calls = [];
        $finish = null;
        $usage = null;
        $ended = false;

        try {
            foreach ($this->pending($request)->asStream() as $event) {
                if ($event instanceof TextDeltaEvent) {
                    $content .= $event->delta;

                    yield new Delta($event->delta);
                }

                if ($event instanceof ToolCallEvent) {
                    $index = count($calls);

                    $calls[] = $event->toolCall;

                    yield new ToolCallStarted($event->toolCall->name, $index);

                    yield new ToolCallArguments($index, json_encode($event->toolCall->arguments() === [] ? new \stdClass : $event->toolCall->arguments(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }

                if ($event instanceof StreamEndEvent) {
                    $ended = true;
                    $finish = $event->finishReason;
                    $usage = $event->usage;
                }
            }
        } catch (AiRequestFailed $e) {
            throw $e;
        } catch (PrismException|RequestException $e) {
            throw $this->mapped($e);
        }

        if (! $ended) {
            throw new StreamTruncated($content, null, null, null);
        }

        $toolCalls = PrismTranslator::toolCalls($calls);

        return new ChatResponse(
            id: '',
            model: $request->model ?? $this->model(),
            content: $content === '' && $toolCalls !== []
                ? null
                : $content,
            toolCalls: $toolCalls,
            finishReason: PrismTranslator::finishReason($finish, $toolCalls !== []),
            usage: PrismTranslator::usage($usage),
            raw: [],
        );
    }

    private function response(TextResponse|StructuredResponse $response, ChatRequest $request): ChatResponse
    {
        $toolCalls = PrismTranslator::toolCalls($response->steps->first()?->toolCalls ?? $response->toolCalls);

        $raw = $response->raw ?? $response->steps->last()?->raw ?? [];

        return new ChatResponse(
            id: (string) ($response->meta->id ?? ''),
            model: (string) ($response->meta->model ?: ($request->model ?? $this->model())),
            content: $toolCalls !== [] && $response->text === ''
                ? null
                : $response->text,
            toolCalls: $toolCalls,
            finishReason: PrismTranslator::finishReason($response->finishReason, $toolCalls !== []),
            usage: PrismTranslator::usage($response->usage),
            raw: is_array($raw) ? $raw : [],
        );
    }

    private function pending(ChatRequest $request): mixed
    {
        [$system, $messages] = PrismTranslator::messages($request->messages);

        $builder = $request->responseFormat === null
            ? Prism::text()->withMaxSteps(1)
            : Prism::structured()->withSchema(PrismTranslator::schema($request->responseFormat));

        $builder = $builder
            ->using($this->provider(), $request->model ?? $this->model(), $this->providerConfig())
            ->withSystemPrompts($system)
            ->withMessages($messages)
            ->withClientOptions($this->clientOptions());

        if ($request->tools !== [] && $request->responseFormat === null) {
            $builder = $builder->withTools(PrismTranslator::tools($request->tools));
        }

        return $this->withOptions($builder, $this->dropped($request->options));
    }

    private function withOptions(mixed $builder, array $options): mixed
    {
        if (isset($options['temperature'])) {
            $builder = $builder->usingTemperature($options['temperature']);
        }

        if (isset($options['top_p'])) {
            $builder = $builder->usingTopP($options['top_p']);
        }

        if (isset($options['max_tokens'])) {
            $builder = $builder->withMaxTokens((int) $options['max_tokens']);
        }

        if ($this->provider() !== 'ollama') {
            return $builder;
        }

        $extra = array_intersect_key($options, array_flip(self::OLLAMA_OPTIONS));

        $keepAlive = $this->config['keep_alive'] ?? null;

        return $builder->withProviderOptions($extra + (blank($keepAlive) ? [] : ['keep_alive' => $keepAlive]));
    }

    private function provider(): string
    {
        return (string) ($this->config['prism_provider'] ?? 'ollama');
    }

    private function providerConfig(): array
    {
        $url = rtrim((string) $this->config['base_url'], '/');

        if ($this->provider() === 'ollama') {
            return ['url' => preg_replace('#/v1$#', '', $url)];
        }

        return array_filter(['url' => $url, 'api_key' => $this->config['api_key'] ?? null]);
    }

    private function clientOptions(): array
    {
        return [
            'timeout' => (int) ($this->config['timeout'] ?? 120),
            'connect_timeout' => (int) ($this->config['connect_timeout'] ?? 2),
        ];
    }

    private function dropped(array $options): array
    {
        foreach ($this->config['drop_options'] ?? [] as $option) {
            if (! array_key_exists($option, $options)) {
                continue;
            }

            unset($options[$option]);

            if (isset($this->warned[$option])) {
                continue;
            }

            $this->warned[$option] = true;

            Log::warning("The '{$this->name()}' provider does not accept '{$option}'; dropped from the request.");
        }

        return $options;
    }

    private function mapped(Throwable $e): AiRequestFailed
    {
        return match (true) {
            $e instanceof PrismRateLimitedException => ProviderErrors::rebuilt(429, $e->getMessage(), $e->retryAfter),
            $e instanceof PrismProviderOverloadedException => ProviderErrors::rebuilt(529, $e->getMessage()),
            $e instanceof PrismRequestTooLargeException => ProviderErrors::rebuilt(413, $e->getMessage()),
            default => $this->fromChain($e),
        };
    }

    private function fromChain(Throwable $e): AiRequestFailed
    {
        for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof RequestException) {
                return ProviderErrors::from($previous->response);
            }
        }

        $status = $e->getCode() >= 400 && $e->getCode() < 600
            ? $e->getCode()
            : 502;

        return ProviderErrors::rebuilt($status, $e->getMessage());
    }
}
