<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capabilities\SendsRawRequests;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Contracts\Usage;
use App\Ai\Debug\DebugLog;
use App\Ai\Embeddings\EmbeddingFailed;
use App\Ai\Http\StreamTruncated;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Psr\Http\Message\StreamInterface;

// One API shape: POST /chat/completions and /embeddings as Ollama, OpenAI, vLLM, llama-server and most gateways speak them. Retries, profiles and the usage log are decorators around it.
final class OpenAiCompatibleDriver implements ChatModel, EmbeddingModel, SendsRawRequests, SupportsContextWindowOverride, SupportsGrammar
{
    private const DEFAULT_CAPABILITIES = ['tools', 'structured', 'seed', 'ctx_override'];

    private array $warned = [];                     // once per option per driver, which the manager keeps for the life of the process

    private ?int $contextWindow = null;             // set by withContextWindow() on an Ollama provider: the one thing /v1/ cannot carry

    public function __construct(private array $config, private array $extraOptions = []) {}

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function dimensions(): int
    {
        return (int) ($this->config['dimensions'] ?? 0);
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability->value, $this->config['capabilities'] ?? self::DEFAULT_CAPABILITIES, true);
    }

    public function withContextWindow(int $tokens): static
    {
        $clone = clone $this;

        if ($this->isOllama()) {
            $clone->contextWindow = $tokens;                                        // Ollama's /v1/ has no field for it: the call drops to /api/chat (Chapter 22, Topic 5)

            return $clone;
        }

        $clone->extraOptions['num_ctx'] = $tokens;                                  // llama-server honours it; OpenAI and most gateways ignore it

        return $clone;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        if ($this->usesNativeApi()) {
            return $this->nativeChat($request);
        }

        $body = $this->body($request) + ['stream' => false];

        $response = $this->http()->post('/chat/completions', $body);

        if (DebugLog::wants($request)) {
            DebugLog::write($request, $this->name(), $body, $response->json() ?? $response->body(), $response->status());
        }

        $this->failIfNotOk($response);

        return $this->normalise($response->json());
    }

    public function stream(ChatRequest $request): Generator
    {
        if ($this->usesNativeApi()) {
            return yield from $this->nativeStream($request);
        }

        $body = $this->body($request) + ['stream' => true, 'stream_options' => ['include_usage' => true]];

        $response = $this->http()->withOptions(['stream' => true])->post('/chat/completions', $body);

        $this->failIfNotOk($response);

        $stream = $response->toPsrResponse()->getBody();

        $content = '';
        $finish = null;
        $usage = null;
        $model = null;
        $id = null;
        $done = false;
        $calls = [];
        $announced = [];

        while (! $stream->eof()) {
            $line = trim($this->readLine($stream));

            if ($line === '') {
                continue;
            }

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));

            if ($payload === '[DONE]') {
                $done = true;

                break;
            }

            $chunk = json_decode($payload, true);
            $id ??= $chunk['id'] ?? null;
            $model ??= $chunk['model'] ?? null;
            $delta = $chunk['choices'][0]['delta']['content'] ?? '';
            $finish = $chunk['choices'][0]['finish_reason'] ?? $finish;
            $usage = $chunk['usage'] ?? $usage;

            if ($delta !== '') {
                $content .= $delta;

                yield new Delta($delta);
            }

            foreach ($chunk['choices'][0]['delta']['tool_calls'] ?? [] as $fragment) {   // accumulate by index, never by position
                $index = $fragment['index'] ?? 0;

                $calls[$index]['id'] ??= $fragment['id'] ?? null;
                $calls[$index]['name'] = ($calls[$index]['name'] ?? '') ?: ($fragment['function']['name'] ?? '');
                $calls[$index]['arguments'] = ($calls[$index]['arguments'] ?? '').($fragment['function']['arguments'] ?? '');

                if ($calls[$index]['name'] !== '' && ! isset($announced[$index])) {
                    $announced[$index] = true;

                    yield new ToolCallStarted($calls[$index]['name'], $index);   // "looking up your invoice…"
                }

                if (($fragment['function']['arguments'] ?? '') !== '') {
                    yield new ToolCallArguments($index, $fragment['function']['arguments']);
                }
            }
        }

        if (! $done) {
            throw new StreamTruncated($content, $finish, $usage, $model);
        }

        ksort($calls);

        $toolCalls = array_values(array_map(fn (array $call) => [
            'id' => $call['id'] ?? '',
            'type' => 'function',
            'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
        ], $calls));

        $usage ??= ['prompt_tokens' => 0, 'completion_tokens' => 0];   // some servers omit it; the usage log estimates

        if (DebugLog::wants($request)) {
            DebugLog::write($request, $this->name(), $body, ['content' => $content, 'tool_calls' => $toolCalls, 'finish_reason' => $finish, 'usage' => $usage], 200);
        }

        return new ChatResponse(
            $id ?? '',
            $model ?? ($request->model ?? $this->model()),
            $content === '' && $toolCalls !== [] ? null : $content,
            $toolCalls,
            FinishReason::fromOpenAi($finish),
            Usage::fromArray($usage),
            [],
        );
    }

    public function embed(array $texts, EmbedKind $kind, ?string $model = null): array
    {
        $prefix = $this->config['prefixes'][$kind->value] ?? '';

        $inputs = array_map(fn (string $text) => $prefix.$text, array_values($texts));

        if ($inputs === []) {
            return [];
        }

        $response = $this->raw('POST', '/embeddings', [
            'model' => $model ?? $this->model(),
            'input' => count($inputs) === 1
                ? $inputs[0]                                                        // a single string is the shape every server accepts
                : $inputs,
        ]);

        $rows = $response['data'] ?? [];

        usort($rows, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        if (count($rows) !== count($inputs)) {
            throw new EmbeddingFailed(sprintf("The embeddings endpoint returned %d vectors for %d passages (model '%s').", count($rows), count($inputs), $model ?? $this->model()));
        }

        return array_map(fn (array $row) => $row['embedding'] ?? throw new EmbeddingFailed("The embeddings endpoint returned no vector for model '".($model ?? $this->model())."'."), $rows);
    }

    // Provider-shaped in, provider-shaped out. Only classes under app/Ai/Native may call this (a test enforces it); the decorators still apply.
    public function raw(string $method, string $path, array $body = []): array
    {
        $response = $this->http()->send($method, $path, ['json' => $body]);

        $this->failIfNotOk($response);

        return $response->json() ?? [];
    }

    // Chapter 23, Topic 6: llama-server's native /completion, where a GBNF grammar masks every token that cannot continue a valid derivation.
    public function completeWithGrammar(string $prompt, string $grammar, int $maxTokens = 4, array $options = [], string $feature = 'grammar'): string
    {
        if (($this->config['native'] ?? null) !== 'llama-server') {
            throw new LogicException("The '{$this->name()}' provider is not llama-server, so it has no grammar endpoint.");
        }

        $body = array_merge(
            array_intersect_key($options, array_flip(['temperature', 'top_p', 'seed'])),
            ['prompt' => $prompt, 'grammar' => $grammar, 'n_predict' => $maxTokens, 'stream' => false, 'cache_prompt' => true],
        );

        $response = $this->nativeHttp()->post('/completion', $body);

        $this->failIfNotOk($response);

        return (string) $response->json('content');
    }

    // Chapter 22, Topic 5. Ollama only, and only when a context window or a keep-alive was asked for: everything else stays on /v1/.
    private function isOllama(): bool
    {
        return ($this->config['native'] ?? null) === 'ollama';
    }

    private function keepAlive(): ?string
    {
        $keepAlive = $this->config['keep_alive'] ?? null;

        return blank($keepAlive)
            ? null
            : (string) $keepAlive;
    }

    private function usesNativeApi(): bool
    {
        return $this->isOllama() && ($this->contextWindow !== null || $this->keepAlive() !== null);
    }

    private function nativeBody(ChatRequest $request, bool $stream): array
    {
        return OllamaNativeShape::request($this->body($request), $this->contextWindow, $this->keepAlive() ?? (string) (config('ai.ollama.keep_alive') ?: '30m'), $stream);
    }

    private function nativeChat(ChatRequest $request): ChatResponse
    {
        $body = $this->nativeBody($request, false);

        $response = $this->nativeHttp()->post('/api/chat', $body);

        if (DebugLog::wants($request)) {
            DebugLog::write($request, $this->name(), $body, $response->json() ?? $response->body(), $response->status());
        }

        $this->failIfNotOk($response);

        return OllamaNativeShape::response($response->json());
    }

    private function nativeStream(ChatRequest $request): Generator
    {
        $body = $this->nativeBody($request, true);

        $response = $this->nativeHttp()->withOptions(['stream' => true])->post('/api/chat', $body);

        $this->failIfNotOk($response);

        $stream = $response->toPsrResponse()->getBody();

        $content = '';
        $calls = [];
        $last = null;

        while (! $stream->eof()) {
            $chunk = json_decode(trim($this->readLine($stream)), true);

            if (! is_array($chunk)) {
                continue;
            }

            $delta = $chunk['message']['content'] ?? '';

            if ($delta !== '') {
                $content .= $delta;

                yield new Delta($delta);
            }

            foreach ($chunk['message']['tool_calls'] ?? [] as $call) {            // Ollama sends a tool call whole, not in fragments
                $index = count($calls);
                $calls[] = $call;

                yield new ToolCallStarted((string) ($call['function']['name'] ?? ''), $index);
                yield new ToolCallArguments($index, OllamaNativeShape::toolCalls([$call])[0]['function']['arguments']);
            }

            if ($chunk['done'] ?? false) {
                $last = $chunk;

                break;
            }
        }

        if ($last === null) {
            throw new StreamTruncated($content, null, null, null);
        }

        if (DebugLog::wants($request)) {
            DebugLog::write($request, $this->name(), $body, ['content' => $content, 'tool_calls' => $calls] + $last, 200);
        }

        return OllamaNativeShape::response(array_replace($last, ['message' => ['content' => $content, 'tool_calls' => $calls]]));
    }

    private function nativeHttp(): PendingRequest
    {
        return Http::baseUrl(preg_replace('#/v1/?$#', '', rtrim((string) $this->config['base_url'], '/')))
            ->withToken((string) ($this->config['api_key'] ?? ''))
            ->acceptJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 2))
            ->timeout((int) ($this->config['timeout'] ?? 120));
    }

    private function body(ChatRequest $request): array
    {
        $body = array_merge($this->dropped($request->options), $this->extraOptions);

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        if ($request->responseFormat !== null) {
            $body['response_format'] = $request->responseFormat;
        }

        return array_merge($body, [
            'model' => $request->model ?? $this->model(),
            'messages' => $request->messages,
        ]);
    }

    // Chapter 37, Topic 6: a reasoning model's thinking tokens are billed as output, so a feature can cap them (ai.features.<name>.thinking_budget, in tokens; 0 = none). Servers name this differently, so it is sent only to a provider that says
    // which parameter takes it (`thinking_budget_param` in its config, in the {"type": "enabled", "budget_tokens": N} shape); everywhere else it is dropped quietly. Not run against a reasoning model in this repository.
    private function thinking(array $options): array
    {
        $budget = $options['thinking_budget'] ?? null;

        unset($options['thinking_budget']);

        $param = $this->config['thinking_budget_param'] ?? null;

        if ($budget === null || blank($param)) {
            return $options;
        }

        $options[$param] = (int) $budget === 0
            ? ['type' => 'disabled']
            : ['type' => 'enabled', 'budget_tokens' => (int) $budget];

        return $options;
    }

    // Chapter 19, Topic 4: an option the provider rejects is dropped, with one warning per option, never an error on every call.
    private function dropped(array $options): array
    {
        $options = $this->thinking($options);

        foreach ($this->config['drop_options'] ?? [] as $option) {
            if (! array_key_exists($option, $options)) {
                continue;
            }

            unset($options[$option]);

            $key = $this->name().'.'.$option;

            if (isset($this->warned[$key])) {
                continue;
            }

            $this->warned[$key] = true;

            Log::warning("The '{$this->name()}' provider does not accept '{$option}'; dropped from the request.");
        }

        return $options;
    }

    private function normalise(array $body): ChatResponse
    {
        $choice = $body['choices'][0] ?? [];

        return new ChatResponse(
            id: (string) ($body['id'] ?? ''),
            model: (string) ($body['model'] ?? ''),
            content: $choice['message']['content'] ?? null,
            toolCalls: $choice['message']['tool_calls'] ?? [],
            finishReason: FinishReason::fromOpenAi($choice['finish_reason'] ?? null),
            usage: Usage::fromArray($body['usage'] ?? []),
            raw: $body,
        );
    }

    private function failIfNotOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw ProviderErrors::from($response);
    }

    private function readLine(StreamInterface $stream): string
    {
        $line = '';

        while (! $stream->eof()) {
            $character = $stream->read(1);

            if ($character === '') {
                break;
            }

            $line .= $character;

            if ($character === "\n") {
                break;
            }
        }

        return $line;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) $this->config['base_url'])
            ->withToken((string) ($this->config['api_key'] ?? ''))
            ->acceptJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 2))   // dead server fails fast
            ->timeout((int) ($this->config['timeout'] ?? 120));                // 120 s local, 60 s cloud
    }
}
