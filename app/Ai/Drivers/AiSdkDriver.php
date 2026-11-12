<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Contracts\Usage;
use App\Ai\Drivers\AiSdk\RelayTool;
use App\Ai\Drivers\AiSdk\SdkAgent;
use App\Ai\Drivers\AiSdk\SdkStructuredAgent;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Http\StreamTruncated;
use Generator;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason as SdkFinishReason;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Throwable;

// Chapter 47, Topic 8: the Laravel AI SDK as one more ChatModel. A request becomes a throwaway agent (SdkAgent) prompted against an on-demand provider built from the role's own config, so the SDK's `config/ai.php` (which shares the `ai` key with this app's) is never read for providers. `sdk_driver` in the role's config names the SDK's driver: `ollama` (its /api/chat, the default here) or `openai-compatible` (/chat/completions).
// The SDK's tool loop is not used: its tools only describe, every call pauses the turn, and the calls come back to the app's loop and ToolGate.
final class AiSdkDriver implements ChatModel
{
    private const DEFAULT_CAPABILITIES = ['tools', 'structured', 'seed'];

    private const OLLAMA_OPTIONS = ['seed', 'top_k', 'min_p', 'stop', 'repeat_penalty', 'presence_penalty', 'frequency_penalty'];

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
        [$agent, $prompt] = $this->agent($request);

        try {
            return $this->response($agent->prompt($prompt, provider: $this->provider(), model: $request->model ?? $this->model(), timeout: $this->timeout()), $request);
        } catch (Throwable $e) {
            throw $this->mapped($e);
        }
    }

    public function stream(ChatRequest $request): Generator
    {
        [$agent, $prompt] = $this->agent($request);

        $content = '';
        $calls = [];
        $reason = null;
        $usage = null;

        try {
            foreach ($agent->stream($prompt, provider: $this->provider(), model: $request->model ?? $this->model(), timeout: $this->timeout()) as $event) {
                if ($event instanceof TextDelta) {
                    $content .= $event->delta;

                    yield new Delta($event->delta);
                }

                if ($event instanceof ToolApprovalRequest) {
                    foreach ($event->pendingApprovals as $pending) {
                        $index = count($calls);

                        $calls[] = ['id' => $pending->id, 'name' => $pending->tool, 'arguments' => $pending->arguments];

                        yield new ToolCallStarted($pending->tool, $index);

                        yield new ToolCallArguments($index, $this->json($pending->arguments));
                    }
                }

                if ($event instanceof StreamEnd) {
                    $reason = $event->reason;
                    $usage = $event->usage;
                }
            }
        } catch (Throwable $e) {
            throw $this->mapped($e);
        }

        if ($usage === null) {
            throw new StreamTruncated($content, null, null, null);
        }

        $toolCalls = array_map(fn (array $call) => $this->toolCall($call['id'], $call['name'], $call['arguments']), $calls);

        return new ChatResponse(
            id: '',
            model: $request->model ?? $this->model(),
            content: $content === '' && $toolCalls !== []
                ? null
                : $content,
            toolCalls: $toolCalls,
            finishReason: $toolCalls !== []
                ? FinishReason::ToolCalls
                : $this->finish(SdkFinishReason::tryFrom((string) $reason) ?? SdkFinishReason::Unknown),
            usage: $this->usage($usage),
            raw: [],
        );
    }

    private function response(TextResponse $response, ChatRequest $request): ChatResponse
    {
        $calls = $response->pendingApprovals->map(fn ($pending) => $this->toolCall($pending->id, $pending->tool, $pending->arguments))->values()->all();

        if ($calls === []) {
            $calls = $response->steps->last()?->toolCalls ?? [];

            $calls = array_map(fn (ToolCall $call) => $this->toolCall($call->id, $call->name, $call->arguments), $calls);
        }

        return new ChatResponse(
            id: '',
            model: (string) ($response->meta->model ?: ($request->model ?? $this->model())),
            content: $calls !== [] && $response->text === ''
                ? null
                : $response->text,
            toolCalls: $calls,
            finishReason: $calls !== []
                ? FinishReason::ToolCalls
                : $this->finish($response->steps->last()?->finishReason ?? SdkFinishReason::Stop),
            usage: $this->usage($response->usage),
            raw: $this->raw($response),
        );
    }

    private function raw(TextResponse $response): array
    {
        $raw = $response->raw ?? null;

        return is_object($raw) && method_exists($raw, 'json')
            ? (array) ($raw->json() ?? [])
            : [];
    }

    private function agent(ChatRequest $request): array
    {
        $system = [];
        $history = [];

        foreach ($request->messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $system[] = $this->text($message['content'] ?? '');

                continue;
            }

            $history[] = $message;
        }

        $last = end($history);

        $prompt = ($last['role'] ?? '') === 'user'
            ? $this->text(array_pop($history)['content'] ?? '')
            : '';

        $tools = array_map(fn (array $definition) => new RelayTool($definition['function'] ?? $definition), $request->tools);

        $arguments = [implode("\n", $system), $this->messages($history), $tools, $request->options, $this->providerOptions($request->options)];

        if ($request->responseFormat === null) {
            return [new SdkAgent(...$arguments), $prompt];
        }

        $schema = ($request->responseFormat['type'] ?? null) === 'json_schema'
            ? ($request->responseFormat['json_schema']['schema'] ?? ['type' => 'object'])
            : ['type' => 'object'];

        return [new SdkStructuredAgent(...$arguments, jsonSchema: $schema), $prompt];
    }

    /** @return list<Message> */
    private function messages(array $history): array
    {
        $messages = [];
        $names = [];
        $results = [];

        foreach ($history as $message) {
            $role = $message['role'] ?? 'user';

            if ($role !== 'tool' && $results !== []) {
                $messages[] = new ToolResultMessage(collect($results));

                $results = [];
            }

            if ($role === 'tool') {
                $id = (string) ($message['tool_call_id'] ?? '');

                $results[] = new ToolResult($id, $names[$id]['name'] ?? '', $names[$id]['arguments'] ?? [], $this->text($message['content'] ?? ''));

                continue;
            }

            if ($role === 'assistant') {
                $calls = [];

                foreach ($message['tool_calls'] ?? [] as $call) {
                    $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?? [];

                    $names[$call['id'] ?? ''] = ['name' => $call['function']['name'] ?? '', 'arguments' => $arguments];

                    $calls[] = new ToolCall((string) ($call['id'] ?? ''), (string) ($call['function']['name'] ?? ''), $arguments);
                }

                $messages[] = new AssistantMessage($this->text($message['content'] ?? ''), collect($calls));

                continue;
            }

            $messages[] = new Message($role, $this->text($message['content'] ?? ''));
        }

        if ($results !== []) {
            $messages[] = new ToolResultMessage(collect($results));
        }

        return $messages;
    }

    // What the agent's own methods cannot carry: the options a server takes in the body, merged in by the SDK as they are.
    private function providerOptions(array $options): array
    {
        if ($this->driver() !== 'ollama') {
            return array_intersect_key($options, array_flip(['seed', 'stop', 'presence_penalty', 'frequency_penalty']));
        }

        $extra = array_intersect_key($options, array_flip(self::OLLAMA_OPTIONS));

        $keepAlive = $this->config['keep_alive'] ?? null;

        return $extra + (blank($keepAlive) ? [] : ['keep_alive' => $keepAlive]);
    }

    private function provider(): string
    {
        $url = rtrim((string) $this->config['base_url'], '/');

        $name = 'sdk:'.$this->name();

        Ai::build(array_filter([
            'name' => $name,
            'driver' => $this->driver(),
            'key' => $this->config['api_key'] ?? null,
            'url' => $this->driver() === 'ollama'
                ? preg_replace('#/v1$#', '', $url)
                : $url,
        ]));

        return $name;
    }

    private function driver(): string
    {
        return (string) ($this->config['sdk_driver'] ?? 'ollama');
    }

    private function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 120);
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id !== ''
                ? $id
                : 'call_'.substr(md5($name.json_encode($arguments)), 0, 12),
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => $this->json($arguments)],
        ];
    }

    private function json(array $arguments): string
    {
        return (string) json_encode($arguments === [] ? new \stdClass : $arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // The SDK reports cache reads under its own name; Ollama's reports none and the OpenAI-compatible provider reports them in prompt_tokens_details, which the SDK may or may not carry across: when it does not, the ledger sees a cached call as an uncached one (Figure 47-9).
    private function usage(TextUsage $usage): Usage
    {
        return new Usage(
            promptTokens: $usage->inputTokens,
            completionTokens: $usage->outputTokens,
            cachedTokens: $usage->cacheReadInputTokens,
            reasoningTokens: $usage->reasoningTokens,
            cacheWriteTokens: $usage->cacheWriteInputTokens,
        );
    }

    private function finish(SdkFinishReason $reason): FinishReason
    {
        return match ($reason) {
            SdkFinishReason::Stop => FinishReason::Stop,
            SdkFinishReason::Length => FinishReason::Length,
            SdkFinishReason::ToolCalls => FinishReason::ToolCalls,
            SdkFinishReason::ContentFilter => FinishReason::ContentFilter,
            default => FinishReason::Truncated,
        };
    }

    private function text(string|array|null $content): string
    {
        if (! is_array($content)) {
            return (string) $content;
        }

        return implode("\n", array_map(fn (array $part) => (string) ($part['text'] ?? ''), array_filter($content, fn (array $part) => ($part['type'] ?? 'text') === 'text')));
    }

    private function mapped(Throwable $e): Throwable
    {
        if ($e instanceof AiRequestFailed) {
            return $e;
        }

        if ($e instanceof ProviderConnectionException) {
            return $e->getPrevious() ?? $e;
        }

        for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof RequestException) {
                return ProviderErrors::from($previous->response);
            }
        }

        if ($e instanceof AiException) {
            return ProviderErrors::rebuilt(502, $e->getMessage());
        }

        return $e;
    }
}
