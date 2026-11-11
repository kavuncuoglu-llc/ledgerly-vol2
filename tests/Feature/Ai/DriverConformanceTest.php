<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Testing\FakeHttp;
use App\Facades\Ai;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// Chapter 19, Topic 4: the same fixtures through every provider that speaks this API shape must give the same normalised answers and the same exception types.
// The fixtures in storage/fixtures/openai-compatible are hand-written to the documented response shapes; `php artisan ai:record` replaces them with real exchanges.

beforeEach(function () {
    Sleep::fake();

    config([
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'example-model',
    ]);
});

function converse(string $provider, string $fixture): array
{
    FakeHttp::replay('openai-compatible', $fixture);

    $response = Ai::chat($provider)->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'What is a late fee?']], feature: 'conformance.test'));

    return [$response];
}

function failWith(string $provider, string $fixture): Throwable
{
    FakeHttp::replay('openai-compatible', $fixture);

    try {
        Ai::chat($provider)->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'conformance.test'));
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException("{$fixture} did not fail.");
}

function streamFixture(string $provider, string $fixture): array
{
    FakeHttp::replay('openai-compatible', $fixture);

    $stream = Ai::chat($provider)->stream(new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'conformance.test'));

    $events = iterator_to_array($stream, false);

    return [$events, $stream->getReturn()];
}

describe('normalised responses', function () {
    it('maps every finish reason onto the enum', function (string $provider, string $fixture, FinishReason $expected) {
        [$response] = converse($provider, $fixture);

        expect($response->finishReason)->toBe($expected);
    })->with(['local', 'frontier', 'vllm'])->with([
        'a normal stop' => ['short-answer', FinishReason::Stop],
        'a token limit' => ['truncated', FinishReason::Length],
        'a tool call' => ['tool-call', FinishReason::ToolCalls],
        'a content filter' => ['content-filter', FinishReason::ContentFilter],
        'a reason nobody has heard of' => ['unknown-finish', FinishReason::Truncated],   // look at it, do not pass it as success
    ]);

    it('fills one Usage shape, cached and reasoning tokens included', function (string $provider) {
        [$response] = converse($provider, 'cached-usage');

        expect([$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->cachedTokens, $response->usage->reasoningTokens])->toBe([2000, 10, 1536, 4])
            ->and($response->usage->total())->toBe(2010);
    })->with(['local', 'frontier', 'vllm']);

    it('keeps the tool call verbatim and the content null', function (string $provider) {
        [$response] = converse($provider, 'tool-call');

        expect($response->content)->toBeNull()
            ->and($response->toolCalls[0]['function']['name'])->toBe('lookup_invoice')
            ->and(json_decode($response->toolCalls[0]['function']['arguments'], true))->toBe(['invoice_number' => '4471', 'month' => null]);
    })->with(['local', 'frontier', 'vllm']);

    it('always keeps the provider\'s own body in raw, the trapdoor of Topic 7', function (string $provider) {
        [$response] = converse($provider, 'short-answer');

        expect($response->raw['id'])->toBe('chatcmpl-fixture')
            ->and($response->raw['choices'][0]['finish_reason'])->toBe('stop');
    })->with(['local', 'frontier', 'vllm']);
});

describe('normalised errors', function () {
    it('names a rate limit, honours Retry-After and gives up after three attempts', function (string $provider) {
        $error = failWith($provider, 'rate-limited');

        expect($error)->toBeInstanceOf(RateLimited::class)
            ->toBeInstanceOf(RequestException::class)
            ->and($error->retryable())->toBeTrue()
            ->and($error->response->status())->toBe(429);

        Http::assertSentCount(3);

        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 2000, 2);
    })->with(['local', 'frontier', 'vllm']);

    it('names an overloaded server and retries it', function (string $provider) {
        expect(failWith($provider, 'overloaded'))->toBeInstanceOf(ProviderUnavailable::class);

        Http::assertSentCount(3);
    })->with(['local', 'frontier', 'vllm']);

    it('names a context overflow and does not retry it', function (string $provider) {
        $error = failWith($provider, 'context-too-long');

        expect($error)->toBeInstanceOf(ContextTooLong::class)
            ->and($error->retryable())->toBeFalse();

        Http::assertSentCount(1);

        Sleep::assertNeverSlept();
    })->with(['local', 'frontier', 'vllm']);

    it('names a bad key and does not retry it, and never echoes the key we sent', function (string $provider) {
        $error = failWith($provider, 'invalid-key');

        expect($error)->toBeInstanceOf(InvalidRequest::class)
            ->and($error)->toBeInstanceOf(AiRequestFailed::class)
            ->and($error->getMessage())->not->toContain('sk-test-frontier-key');

        Http::assertSentCount(1);
    })->with(['local', 'frontier', 'vllm']);
});

describe('normalised streams', function () {
    it('yields deltas, then a return value that carries the whole reply', function (string $provider) {
        [$events, $response] = streamFixture($provider, 'stream-text');

        expect(collect($events)->whereInstanceOf(Delta::class)->map->text->implode(''))->toBe('The fee is 1.5%.')
            ->and($response->content)->toBe('The fee is 1.5%.')
            ->and($response->finishReason)->toBe(FinishReason::Stop)
            ->and($response->usage->promptTokens)->toBe(68);
    })->with(['local', 'frontier', 'vllm']);

    it('announces a tool call before any of its arguments (Exercise 4), and assembles them', function (string $provider) {
        [$events, $response] = streamFixture($provider, 'stream-tool-call');

        $kinds = collect($events)->map(fn ($event) => $event::class)->all();

        expect($kinds[0])->toBe(ToolCallStarted::class)
            ->and(array_search(ToolCallArguments::class, $kinds, true))->toBeGreaterThan(0)
            ->and($response->finishReason)->toBe(FinishReason::ToolCalls)
            ->and(json_decode($response->toolCalls[0]['function']['arguments'], true))->toBe(['invoice_number' => '4471']);
    })->with(['local', 'frontier', 'vllm']);
});

describe('each provider keeps to its own endpoint and key', function () {
    it('sends frontier requests to the frontier URL with the frontier key, and nothing of it to local', function () {
        FakeHttp::replay('openai-compatible', 'short-answer');

        Ai::chat('frontier')->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'conformance.test'));
        Ai::chat('local')->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'conformance.test'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://frontier.example.com/v1/') && $request->hasHeader('Authorization', 'Bearer sk-test-frontier-key'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://localhost:11434/v1/') && $request->hasHeader('Authorization', 'Bearer ollama'));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'localhost') && str_contains(json_encode($request->headers()), 'sk-test-frontier-key'));
    });

    it('drops the options a hosted API rejects, once, and leaves local alone', function () {
        FakeHttp::replay('openai-compatible', 'short-answer');

        $request = new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'conformance.test', options: ['min_p' => 0.05, 'seed' => 7]);

        Ai::chat('frontier')->chat($request);
        Ai::chat('local')->chat($request);

        Http::assertSent(fn (Request $sent) => str_contains($sent->url(), 'frontier') && ! isset($sent['min_p']) && $sent['seed'] === 7);

        Http::assertSent(fn (Request $sent) => str_contains($sent->url(), 'localhost') && $sent['min_p'] === 0.05);
    });
});

describe('the fake driver passes the same suite', function () {
    it('reports the finish reasons it was scripted with, and the same stream event order', function () {
        Ai::fake()
            ->for('conformance.test')
            ->respondWith('A late fee is an extra charge.')
            ->respondWith('A late fee is', finish: FinishReason::Length)
            ->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null]);

        $request = new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'conformance.test');

        expect(Ai::chat('local')->chat($request)->finishReason)->toBe(FinishReason::Stop)
            ->and(Ai::chat('local')->chat($request)->finishReason)->toBe(FinishReason::Length);

        $stream = Ai::chat('local')->stream($request);

        $kinds = collect(iterator_to_array($stream, false))->map(fn ($event) => $event::class)->all();

        expect($kinds)->toBe([ToolCallStarted::class, ToolCallArguments::class])
            ->and($stream->getReturn()->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($stream->getReturn())->not->toBeInstanceOf(Completed::class);
    });
});
