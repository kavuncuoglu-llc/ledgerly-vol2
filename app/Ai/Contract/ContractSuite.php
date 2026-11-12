<?php

namespace App\Ai\Contract;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Drivers\DriverFactory;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Testing\FakeHttp;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

// Chapter 47, Topic 8: the one suite every driver must pass, whichever package is underneath. The same requests go to the driver under test with the HTTP faked, and what comes back must be the app's own normalised answer: text and finish reasons, usage exact enough for the ledger, tool calls handed back for ToolGate and never run, a reply that validates against its schema, streams in the app's event order, and provider failures as the app's own exception types.
// A driver is tested on the wire format it speaks to a server: `ollama` (/api/chat, which all three drivers can speak to a local server) and `openai-compatible` (/chat/completions, which the raw driver and the SDK speak and Prism's providers mostly do not). The Ollama fixtures were recorded from a live Ollama; the others are the hand-written ones the Chapter 19 suite has used since Volume 1.
final class ContractSuite
{
    public const DRIVERS = ['raw', 'prism', 'ai-sdk'];

    public const WIRES = ['ollama', 'openai-compatible'];

    private const KEY = 'sk-test-contract-key';

    private const LOOKUP_TOOL = [
        'type' => 'function',
        'function' => [
            'name' => 'lookup_invoice',
            'description' => 'Look up an invoice the customer owns by its number',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'invoice_number' => ['type' => 'string', 'description' => 'The invoice number, such as 4471'],
                    'month' => ['type' => ['string', 'null'], 'description' => 'Optional month'],
                ],
                'required' => ['invoice_number'],
            ],
        ],
    ];

    private const REPLY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['paid', 'open', 'overdue']],
            'amount_due' => ['type' => 'number'],
        ],
        'required' => ['status', 'amount_due'],
    ];

    // A check a package cannot pass on a wire, with the reason. It is reported as a gap and the suite passes; the day the package passes it, the suite fails until the line is removed, so a gap cannot outlive its cause.
    public const GAPS = [
        'ai-sdk|ollama|tool history reaches the model' => "the SDK's prompt() needs a final user message, so a history that ends with a tool result goes out with an empty user turn after it: the model is asked to answer an empty question (more calls, more input tokens)",
        'ai-sdk|openai-compatible|tool history reaches the model' => "the SDK's prompt() needs a final user message, so a history that ends with a tool result goes out with an empty user turn after it: the model is asked to answer an empty question (more calls, more input tokens)",
        'prism|ollama|it adds no limit or option the app did not ask for' => "Prism's Ollama provider sends num_predict 2048 whenever no limit is asked for, so every answer is capped at 2,048 tokens unless the request names a limit",
        'prism|ollama|a rate limit is RateLimited and says when' => "Prism's Ollama provider throws PrismRateLimitedException::make([]) and drops the Retry-After header, so the retry decorator falls back to its own back-off",
    ];

    public function applies(string $driver, string $wire): bool
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("[{$driver}] is not a driver: ".implode(', ', self::DRIVERS).'.');
        }

        if (! in_array($wire, self::WIRES, true)) {
            throw new InvalidArgumentException("[{$wire}] is not a wire format: ".implode(', ', self::WIRES).'.');
        }

        return ! ($driver === 'prism' && $wire === 'openai-compatible');
    }

    public function make(string $driver, string $wire): ChatModel
    {
        $config = [
            'name' => "contract-{$driver}",
            'model' => 'llama3.1:8b',
            'api_key' => self::KEY,
            'timeout' => 30,
            'connect_timeout' => 2,
            'base_url' => $wire === 'ollama'
                ? 'http://localhost:11434/v1'
                : 'https://contract.example.com/v1',
        ];

        return DriverFactory::make($driver, match ($driver) {
            'raw' => $wire === 'ollama'
                ? $config + ['native' => 'ollama', 'keep_alive' => '5m']
                : $config,
            'prism' => $config + ['prism_provider' => 'ollama'],
            'ai-sdk' => $config + ['sdk_driver' => $wire === 'ollama' ? 'ollama' : 'openai-compatible'],
        });
    }

    /** @return list<ContractResult> */
    public function run(string $driver, ?string $wire = null): array
    {
        $wires = $wire === null
            ? self::WIRES
            : [$wire];

        $results = [];

        foreach ($wires as $each) {
            if (! $this->applies($driver, $each)) {
                continue;
            }

            array_push($results, ...$this->against($this->make($driver, $each), $driver, $each));
        }

        return $results;
    }

    /** @return list<ContractResult> */
    public function against(ChatModel $model, string $label, string $wire): array
    {
        Sleep::fake();

        $original = Http::getFacadeRoot();

        try {
            $results = [];

            foreach ($this->checks($wire) as $name => $check) {
                $results[] = $this->one($model, $label, $wire, $name, $check);
            }

            return $results;
        } finally {
            Sleep::fake(false);

            Http::swap($original);
        }
    }

    /** @return array<string, Closure(ChatModel): string> each returns what it saw, and throws ContractFailure when that is wrong */
    private function checks(string $wire): array
    {
        $checks = [
            'text is normalised' => fn (ChatModel $m) => $this->text($m, $wire),
            'a cut-off answer says why' => fn (ChatModel $m) => $this->cutOff($m, $wire),
            'usage is exact' => fn (ChatModel $m) => $this->usage($m, $wire),
            'tool calls come back unrun' => fn (ChatModel $m) => $this->toolCalls($m, $wire),
            'tool history reaches the model' => fn (ChatModel $m) => $this->toolHistory($m, $wire),
            'structured output validates' => fn (ChatModel $m) => $this->structured($m, $wire),
            'the request is faithful' => fn (ChatModel $m) => $this->fidelity($m, $wire),
            'it adds no limit or option the app did not ask for' => fn (ChatModel $m) => $this->nothingExtra($m, $wire),
            'a stream yields deltas, then the reply' => fn (ChatModel $m) => $this->streamText($m, $wire),
            'a streamed tool call is announced first' => fn (ChatModel $m) => $this->streamToolCall($m, $wire),
            'a rate limit is RateLimited and says when' => fn (ChatModel $m) => $this->failure($m, $wire, 429, ['error' => ['message' => 'Rate limit reached']], ['Retry-After' => '2'], RateLimited::class, 2),
            'an overload is ProviderUnavailable' => fn (ChatModel $m) => $this->failure($m, $wire, 503, ['error' => ['message' => 'The server is overloaded']], [], ProviderUnavailable::class),
            'a context overflow is ContextTooLong' => fn (ChatModel $m) => $this->failure($m, $wire, 400, ['error' => ['message' => "This model's maximum context length is 8192 tokens", 'code' => 'context_length_exceeded']], [], ContextTooLong::class),
            'a bad key or model is InvalidRequest, and never echoes the key' => fn (ChatModel $m) => $this->invalid($m, $wire),
        ];

        if ($wire === 'openai-compatible') {
            $checks['every provider finish reason lands on the enum'] = fn (ChatModel $m) => $this->finishReasons($m);
        }

        return $checks;
    }

    private function one(ChatModel $model, string $label, string $wire, string $name, Closure $check): ContractResult
    {
        $this->fresh();

        $gap = self::GAPS["{$label}|{$wire}|{$name}"] ?? null;

        try {
            $detail = $check($model);
        } catch (ContractFailure $e) {
            return $gap === null
                ? new ContractResult($label, $wire, $name, false, $e->getMessage())
                : new ContractResult($label, $wire, $name, true, "known gap: {$gap}", gap: true);
        } catch (Throwable $e) {
            return new ContractResult($label, $wire, $name, false, $e::class.': '.$e->getMessage());
        }

        return $gap === null
            ? new ContractResult($label, $wire, $name, true, $detail)
            : new ContractResult($label, $wire, $name, false, 'the known gap is closed: remove it from ContractSuite::GAPS ('.$gap.')');
    }

    private function fresh(): void
    {
        Http::swap(new Factory);

        Http::preventStrayRequests();
    }

    private function text(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'short-answer');

        $reply = $model->chat($this->ask());

        $this->expect($reply->finishReason === FinishReason::Stop, "finish reason {$reply->finishReason->value}, wanted stop");
        $this->expect(is_string($reply->content) && trim($reply->content) !== '', 'content is empty');
        $this->expect($reply->toolCalls === [], 'a plain answer carried tool calls');
        $this->expect($reply->model !== '', 'the model name is empty');

        return mb_substr((string) $reply->content, 0, 40);
    }

    private function cutOff(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'truncated');

        $reply = $model->chat($this->ask());

        $this->expect($reply->finishReason === FinishReason::Length, "finish reason {$reply->finishReason->value}, wanted length");
        $this->expect($reply->wasCutOff(), 'wasCutOff() is false');

        return 'length';
    }

    private function finishReasons(ChatModel $model): string
    {
        $wanted = [
            'short-answer' => FinishReason::Stop,
            'truncated' => FinishReason::Length,
            'tool-call' => FinishReason::ToolCalls,
            'content-filter' => FinishReason::ContentFilter,
            'unknown-finish' => FinishReason::Truncated,
        ];

        foreach ($wanted as $fixture => $reason) {
            $this->serve('openai-compatible', $fixture);

            $got = $model->chat($this->ask())->finishReason;

            $this->expect($got === $reason, "{$fixture}: {$got->value}, wanted {$reason->value}");
        }

        return count($wanted).' reasons';
    }

    private function usage(ChatModel $model, string $wire): string
    {
        $fixture = $wire === 'ollama'
            ? 'short-answer'
            : 'cached-usage';

        $recorded = FakeHttp::load($wire, $fixture)['response']['body'];

        $this->serve($wire, $fixture);

        $usage = $model->chat($this->ask())->usage;

        if ($wire === 'ollama') {
            $this->expect($usage->promptTokens === $recorded['prompt_eval_count'], "prompt tokens {$usage->promptTokens}, the server said {$recorded['prompt_eval_count']}");
            $this->expect($usage->completionTokens === $recorded['eval_count'], "completion tokens {$usage->completionTokens}, the server said {$recorded['eval_count']}");

            return "{$usage->promptTokens} in, {$usage->completionTokens} out";
        }

        $seen = [$usage->promptTokens, $usage->completionTokens, $usage->cachedTokens, $usage->reasoningTokens];

        $this->expect($seen === [2000, 10, 1536, 4], 'prompt, completion, cached, reasoning were '.json_encode($seen).', the server said [2000,10,1536,4]: the cost ledger would drift');

        return 'prompt 2000, completion 10, cached 1536, reasoning 4';
    }

    private function toolCalls(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'tool-call');

        $reply = $model->chat($this->ask(tools: [self::LOOKUP_TOOL]));

        $this->expect($reply->finishReason === FinishReason::ToolCalls, "finish reason {$reply->finishReason->value}, wanted tool_calls");
        $this->expect($reply->content === null, 'content should be null when the model only called a tool');
        $this->expect(count($reply->toolCalls) === 1, count($reply->toolCalls).' tool calls, wanted 1');

        $call = $reply->toolCalls[0];

        $this->expect(($call['type'] ?? null) === 'function', 'the call is not in the function shape');
        $this->expect(($call['function']['name'] ?? null) === 'lookup_invoice', 'the tool name is not lookup_invoice');
        $this->expect(is_string($call['function']['arguments'] ?? null), 'arguments are not a JSON string');
        $this->expect((json_decode($call['function']['arguments'], true)['invoice_number'] ?? null) === '4471', 'invoice_number is not 4471');
        $this->expect(($call['id'] ?? '') !== '', 'the call has no id');

        $sent = $this->sent();

        $this->expect(str_contains(json_encode($sent), 'lookup_invoice'), 'the tool was never described to the model');
        $this->expect(str_contains(json_encode($sent), 'invoice_number'), "the tool's parameters were never described");

        return 'lookup_invoice(4471), not run';
    }

    private function toolHistory(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'short-answer');

        $call = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']];

        $model->chat(new ChatRequest(
            messages: [
                ['role' => 'system', 'content' => 'You are a billing assistant.'],
                ['role' => 'user', 'content' => 'Why did invoice 4471 go up?'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'late fee 18.00'],
            ],
            feature: 'contract.test',
            tools: [self::LOOKUP_TOOL],
        ));

        $body = json_encode($this->sent());

        $this->expect(str_contains($body, 'late fee 18.00'), 'the tool result never reached the model');
        $this->expect(str_contains($body, 'You are a billing assistant.'), 'the system message never reached the model');

        $roles = array_column($this->sent()['messages'], 'role');

        $this->expect($roles === ['system', 'user', 'assistant', 'tool'], 'the history went out as ['.implode(', ', $roles).'], wanted [system, user, assistant, tool]');

        return 'result and system message sent';
    }

    private function structured(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'structured');

        $reply = $model->chat(new ChatRequest(
            messages: [['role' => 'user', 'content' => 'Invoice 4472 is open and $100.00 is due. Give its status and amount due.']],
            feature: 'contract.test',
            responseFormat: ['type' => 'json_schema', 'json_schema' => ['name' => 'invoice_status', 'strict' => true, 'schema' => self::REPLY_SCHEMA]],
        ));

        $decoded = json_decode((string) $reply->content, true);

        $this->expect(is_array($decoded), 'the reply is not JSON: '.mb_substr((string) $reply->content, 0, 60));
        $this->expect(in_array($decoded['status'] ?? null, ['paid', 'open', 'overdue'], true), 'status is not in the enum');
        $this->expect(is_numeric($decoded['amount_due'] ?? null), 'amount_due is not a number');
        $this->expect(str_contains(json_encode($this->sent()), 'amount_due'), 'the schema was never sent to the model');

        return (string) json_encode($decoded);
    }

    private function fidelity(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'short-answer');

        $model->chat(new ChatRequest(
            messages: [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'What is a late fee?']],
            feature: 'contract.test',
            model: 'override-model:7b',
            options: ['temperature' => 0.2, 'max_tokens' => 60, 'seed' => 7],
        ));

        $request = $this->lastRequest();

        $body = $request->data();

        $this->expect(($body['model'] ?? null) === 'override-model:7b', 'the model override was not sent');
        $this->expect(array_column($body['messages'], 'role') === ['system', 'user'], 'roles sent: '.implode(',', array_column($body['messages'], 'role')));

        $options = $wire === 'ollama'
            ? ($body['options'] ?? [])
            : $body;

        $limit = $wire === 'ollama'
            ? 'num_predict'
            : 'max_tokens';

        $this->expect(($options['temperature'] ?? null) == 0.2, 'temperature was not sent');
        $this->expect(($options[$limit] ?? null) === 60, "{$limit} was not sent as 60");
        $this->expect(($options['seed'] ?? null) === 7, 'seed was not sent');

        if ($wire === 'openai-compatible') {
            $this->expect($request->hasHeader('Authorization', 'Bearer '.self::KEY), 'the key was not sent as a bearer token');
        }

        return 'model, roles, temperature, limit, seed';
    }

    // The app asks for one thing (a temperature). Whatever else is in the body that changes what the model does (a token limit, a sampling option) came from the package, not from the app: a hidden cap on every answer is the kind of default Chapter 47, Topic 3 says to look for.
    private function nothingExtra(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'short-answer');

        $model->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'What is a late fee?']], feature: 'contract.test', options: ['temperature' => 0.2]));

        $body = $this->sent();

        $options = $wire === 'ollama'
            ? array_keys($body['options'] ?? [])
            : array_diff(array_keys($body), ['model', 'messages', 'stream', 'stream_options', 'tools', 'tool_choice', 'temperature']);

        $extra = array_values(array_diff($options, ['temperature']));

        $this->expect($extra === [], 'the body carries '.implode(', ', $extra).' beyond the temperature that was asked for'.($wire === 'ollama' && isset($body['options']['num_predict']) ? ' (num_predict '.$body['options']['num_predict'].')' : ''));

        return 'temperature only';
    }

    private function streamText(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'stream-text');

        $stream = $model->stream($this->ask());

        $events = iterator_to_array($stream, false);

        $reply = $stream->getReturn();

        $text = collect($events)->filter(fn ($event) => property_exists($event, 'text'))->map->text->implode('');

        $this->expect($text !== '', 'no deltas');
        $this->expect($reply instanceof ChatResponse, 'no reply returned at the end');
        $this->expect($reply->content === $text, 'the reply is not the deltas joined');
        $this->expect($reply->finishReason === FinishReason::Stop, "finish reason {$reply->finishReason->value}, wanted stop");
        $this->expect($reply->usage->promptTokens > 0 && $reply->usage->completionTokens > 0, 'the stream reported no usage');

        return count($events).' deltas';
    }

    private function streamToolCall(ChatModel $model, string $wire): string
    {
        $this->serve($wire, 'stream-tool-call');

        $stream = $model->stream($this->ask(tools: [self::LOOKUP_TOOL]));

        $kinds = array_map(fn ($event) => $event::class, iterator_to_array($stream, false));

        $reply = $stream->getReturn();

        $this->expect(($kinds[0] ?? null) === ToolCallStarted::class, 'the first event is not ToolCallStarted');
        $this->expect(in_array(ToolCallArguments::class, $kinds, true), 'no arguments were streamed');
        $this->expect($reply->finishReason === FinishReason::ToolCalls, "finish reason {$reply->finishReason->value}, wanted tool_calls");
        $this->expect((json_decode($reply->toolCalls[0]['function']['arguments'] ?? '', true)['invoice_number'] ?? null) === '4471', 'the assembled arguments lost the invoice number');

        return 'started, arguments, assembled';
    }

    private function failure(ChatModel $model, string $wire, int $status, array $body, array $headers, string $expected, ?int $retryAfter = null): string
    {
        $this->fresh();

        Http::fake(['*' => Http::response($wire === 'ollama' ? ['error' => $body['error']['message']] : $body, $status, $headers)]);

        $caught = $this->failed($model);

        $this->expect($caught instanceof $expected, 'raised '.$caught::class.", wanted {$expected}");
        $this->expect($caught instanceof AiRequestFailed && $caught->response->status() === $status, 'the response status was lost');

        if ($retryAfter !== null) {
            $this->expect($caught instanceof AiRequestFailed && $caught->retryAfterSeconds() === $retryAfter, 'Retry-After was lost');
        }

        $attempts = Http::recorded()->count();

        return class_basename($caught).' after '.$attempts.' HTTP attempt'.($attempts === 1 ? '' : 's');
    }

    private function invalid(ChatModel $model, string $wire): string
    {
        $wire === 'ollama'
            ? $this->serve('ollama', 'unknown-model')
            : $this->serve('openai-compatible', 'invalid-key');

        $caught = $this->failed($model);

        $this->expect($caught instanceof InvalidRequest, 'raised '.$caught::class.', wanted InvalidRequest');
        $this->expect(! str_contains($caught->getMessage(), self::KEY), 'the exception message echoes the key');
        $this->expect(! $caught->retryable(), 'an invalid request is marked retryable');

        return 'InvalidRequest';
    }

    private function failed(ChatModel $model): AiRequestFailed
    {
        try {
            $model->chat($this->ask());
        } catch (AiRequestFailed $e) {
            return $e;
        } catch (Throwable $e) {
            throw new ContractFailure('raised '.$e::class.', which is not one of the app\'s exception types');
        }

        throw new ContractFailure('the failed call did not raise');
    }

    private function serve(string $wire, string $fixture): void
    {
        $this->fresh();

        $endpoint = $wire === 'ollama'
            ? '*/api/chat'
            : '*/chat/completions';

        FakeHttp::replay($wire, $fixture, $endpoint);
    }

    private function ask(array $tools = []): ChatRequest
    {
        return new ChatRequest(messages: [['role' => 'user', 'content' => 'What is a late fee?']], feature: 'contract.test', tools: $tools);
    }

    private function lastRequest(): Request
    {
        $recorded = Http::recorded();

        $this->expect($recorded->isNotEmpty(), 'nothing was sent');

        return $recorded->last()[0];
    }

    private function sent(): array
    {
        return $this->lastRequest()->data();
    }

    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new ContractFailure($message);
        }
    }
}
