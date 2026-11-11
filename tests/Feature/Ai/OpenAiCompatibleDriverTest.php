<?php

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Contracts\Usage;
use App\Ai\Events\ModelCalled;
use App\Ai\Http\StreamTruncated;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

function clientCompletion(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'chatcmpl-9f2a7c',
        'object' => 'chat.completion',
        'created' => 1762000000,
        'model' => 'llama3.1:8b',
        'system_fingerprint' => 'fp_4c1e',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'The fee is 1.5% of the outstanding balance per month, prorated by day.',
                'tool_calls' => null,
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 68, 'completion_tokens' => 19, 'total_tokens' => 87],
    ], $overrides);
}

function askOnce(array $options = [], string $feature = 'support.reply'): ChatResponse
{
    return Ai::for($feature)->chat(ChatRequest::make([['role' => 'user', 'content' => 'What is a late fee?']], $options, $feature));
}

// The Chapter 8 tests read strings and ToolCallStarted from the stream; the provider layer yields typed events. This puts them back so the same assertions still say the same thing.
function legacyStream(array $messages, array $options = [], string $feature = 'unknown'): Generator
{
    $events = Ai::for($feature)->stream(ChatRequest::make($messages, $options, $feature));

    foreach ($events as $event) {
        if ($event instanceof Delta) {
            yield $event->text;
        }

        if ($event instanceof ToolCallStarted) {
            yield $event;
        }
    }

    return $events->getReturn();
}

function sse(array $chunks, bool $done = true): string
{
    $body = collect($chunks)->map(fn ($chunk) => 'data: '.json_encode($chunk)."\n\n")->implode('');

    return $done
        ? $body."data: [DONE]\n\n"
        : $body;
}

function deltaChunk(string $content): array
{
    return ['id' => 'chatcmpl-9f2a', 'model' => 'llama3.1:8b', 'choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => null]]];
}

function lateFeeStream(bool $done = true): string
{
    return sse([
        ['id' => 'chatcmpl-9f2a', 'model' => 'llama3.1:8b', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]],
        deltaChunk('The'),
        deltaChunk(' fee'),
        deltaChunk(' is'),
        deltaChunk(' 1.5%.'),
        ['id' => 'chatcmpl-9f2a', 'model' => 'llama3.1:8b', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 68, 'completion_tokens' => 19, 'total_tokens' => 87]],
    ], $done);
}

function fakeStream(string $body): void
{
    Http::fake(['*/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
}

function drain(Generator $stream): array
{
    $deltas = [];

    foreach ($stream as $delta) {
        $deltas[] = $delta;
    }

    return $deltas;
}

beforeEach(function () {
    Sleep::fake();
});

it('is one manager, and it hands out the same decorated driver for the same name', function () {
    expect(app(AiManager::class))->toBe(app(AiManager::class))
        ->and(Ai::chat('local'))->toBe(Ai::chat('local'))
        ->and(Ai::for('support.reply'))->toBe(Ai::chat('local'));
});

it('posts the Figure 8-1 request to /chat/completions with a bearer token', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    askOnce(['profile' => 'assistant', 'max_tokens' => 200]);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'http://localhost:11434/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer ollama')
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === [
                'temperature' => 0.7,
                'top_p' => 0.9,
                'max_tokens' => 200,
                'model' => 'llama3.1:8b',
                'messages' => [['role' => 'user', 'content' => 'What is a late fee?']],
                'stream' => false,
            ];
    });
});

it('merges a named profile with per-call options, options winning', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    askOnce(['profile' => 'deterministic', 'temperature' => 0.3, 'stop' => ["\n\n"]]);

    Http::assertSent(fn (Request $request) => $request['temperature'] === 0.3
        && $request['seed'] === 42
        && $request['top_p'] === 1.0
        && $request['stop'] === ["\n\n"]
        && ! isset($request['profile']));
});

it('defaults to the assistant profile and the configured model', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    config(['ai.providers.local.model' => 'qwen3:8b']);
    app()->forgetInstance(AiManager::class);

    askOnce();

    Http::assertSent(fn (Request $request) => $request['model'] === 'qwen3:8b' && $request['temperature'] === 0.7);
});

it('lets a call pick its own model', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    askOnce(['model' => 'llama3.2-vision:11b']);

    Http::assertSent(fn (Request $request) => $request['model'] === 'llama3.2-vision:11b');
});

it('rejects an unknown profile', function () {
    Http::fake();

    askOnce(['profile' => 'wild']);
})->throws(InvalidArgumentException::class, "Unknown sampling profile 'wild'");

it('sends image parts through untouched', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    $parts = [['type' => 'text', 'text' => 'Read this'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAAA']]];

    Ai::chat()->chat(new ChatRequest(messages: [['role' => 'user', 'content' => $parts]], feature: 'test.vision'));

    Http::assertSent(fn (Request $request) => $request['messages'][0]['content'] === $parts);
});

it('parses the response into a ChatResponse', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    $reply = askOnce();

    expect($reply)->toBeInstanceOf(ChatResponse::class)
        ->and($reply->id)->toBe('chatcmpl-9f2a7c')
        ->and($reply->model)->toBe('llama3.1:8b')
        ->and($reply->content)->toBe('The fee is 1.5% of the outstanding balance per month, prorated by day.')
        ->and($reply->toolCalls)->toBe([])
        ->and($reply->finishReason)->toBe(FinishReason::Stop)
        ->and($reply->usage->promptTokens)->toBe(68)
        ->and($reply->usage->completionTokens)->toBe(19)
        ->and($reply->raw['system_fingerprint'])->toBe('fp_4c1e');
});

it('keeps tool calls and a null content', function () {
    $toolCalls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"4471"}']]];

    Http::fake(['*/chat/completions' => Http::response(clientCompletion(['choices' => [['message' => ['content' => null, 'tool_calls' => $toolCalls], 'finish_reason' => 'tool_calls']]]))]);

    $reply = askOnce();

    expect($reply->content)->toBeNull()
        ->and($reply->toolCalls)->toBe($toolCalls)
        ->and($reply->finishReason)->toBe(FinishReason::ToolCalls);
});

it('reads the provider extras out of usage', function () {
    $usage = Usage::fromArray([
        'prompt_tokens' => 120,
        'completion_tokens' => 30,
        'prompt_tokens_details' => ['cached_tokens' => 100],
        'completion_tokens_details' => ['reasoning_tokens' => 12],
    ]);

    expect($usage->cachedTokens)->toBe(100)
        ->and($usage->reasoningTokens)->toBe(12)
        ->and($usage->toArray())->toBe(['prompt_tokens' => 120, 'completion_tokens' => 30, 'cached_tokens' => 100, 'reasoning_tokens' => 12])
        ->and(Usage::fromArray([])->toArray())->toBe(['prompt_tokens' => 0, 'completion_tokens' => 0]);
});

it('dispatches ModelCalled after every response and the Chapter 2 listener logs it', function () {
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    askOnce(feature: 'support.reply');

    $call = ModelCall::sole();

    expect($call->feature)->toBe('support.reply')
        ->and($call->provider)->toBe('local')
        ->and($call->model)->toBe('llama3.1:8b')
        ->and($call->prompt_tokens)->toBe(68)
        ->and($call->completion_tokens)->toBe(19)
        ->and($call->finish_reason)->toBe('stop')
        ->and($call->latency_ms)->toBeInt()
        ->and($call->cost_micros)->toBe(0);
});

it('passes the event the arguments Figure 8-3 lists', function () {
    Event::fake([ModelCalled::class]);
    Http::fake(['*/chat/completions' => Http::response(clientCompletion())]);

    askOnce(feature: 'cli.test');

    Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event) => $event->feature === 'cli.test'
        && $event->provider === 'local'
        && $event->model === 'llama3.1:8b'
        && $event->usage === ['prompt_tokens' => 68, 'completion_tokens' => 19]
        && $event->finishReason === 'stop'
        && $event->conversationId === null);
});

it('records a truncated completion and warns', function () {
    Log::spy();
    Http::fake(['*/chat/completions' => Http::response(clientCompletion(['choices' => [['finish_reason' => 'length']]]))]);

    $reply = askOnce(['max_tokens' => 5]);

    expect($reply->finishReason)->toBe(FinishReason::Length)
        ->and(ModelCall::sole()->finish_reason)->toBe('length');

    Log::shouldHaveReceived('warning')->with('Completion truncated', Mockery::any())->once();
});

describe('retry policy', function () {
    it('retries a 503 and succeeds on the next attempt', function () {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => 'busy'], 503)
            ->push(clientCompletion())]);

        $reply = askOnce();

        expect($reply->content)->toStartWith('The fee is')
            ->and(ModelCall::count())->toBe(1);

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    });

    it('retries rate limits and server errors, three attempts in all, then throws', function (int $status) {
        Http::fake(['*/chat/completions' => Http::response(['error' => 'no'], $status)]);

        try {
            askOnce();
        } catch (RequestException $e) {
            expect($e->response->status())->toBe($status);
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        expect(ModelCall::count())->toBe(0);
    })->with([408, 409, 425, 429, 500, 502, 503, 504, 529]);

    it('does not retry the caller\'s own mistakes', function (int $status) {
        Http::fake(['*/chat/completions' => Http::response(['error' => 'no'], $status)]);

        expect(fn () => askOnce())->toThrow(RequestException::class);

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    })->with([400, 401, 403, 404, 422]);

    it('honours Retry-After', function () {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '3'])
            ->push(clientCompletion())]);

        askOnce();

        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 3000, 1);
    });

    it('backs off exponentially with jitter when there is no Retry-After', function () {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push('', 503)
            ->push('', 503)
            ->push(clientCompletion())]);

        askOnce();

        $slept = [];

        Sleep::assertSlept(function ($duration) use (&$slept) {
            $slept[] = (int) $duration->totalMilliseconds;

            return true;
        }, 2);

        expect($slept)->toHaveCount(2)
            ->and($slept[0])->toBeBetween(500, 750)
            ->and($slept[1])->toBeBetween(1000, 1250);
    });

    it('retries a dropped connection', function () {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->pushFailedConnection('connection reset')
            ->push(clientCompletion())]);

        expect(askOnce()->content)->toStartWith('The fee is');

        Http::assertSentCount(2);
    });

    it('gives up on a server that is down after three attempts', function () {
        Http::fake(['*/chat/completions' => Http::failedConnection()]);

        expect(fn () => askOnce())->toThrow(ConnectionException::class);

        Http::assertSentCount(3);
    });

    it('does not retry a reply that is wrong but arrived fine', function () {
        Http::fake(['*/chat/completions' => Http::response(clientCompletion(['choices' => [['finish_reason' => 'length']]]))]);

        askOnce();

        Http::assertSentCount(1);
    });

    it('also protects embeddings', function () {
        Http::fake(['*/embeddings' => Http::sequence()->push('', 503)->push(['data' => [['embedding' => [0.1]]]])]);

        $vectors = Ai::embed()->embed(['x'], EmbedKind::Document, 'nomic-embed-text');

        expect($vectors[0])->toBe([0.1]);

        Http::assertSentCount(2);
    });
});

describe('streaming', function () {
    it('yields each content delta and returns the assembled ChatResponse', function () {
        fakeStream(lateFeeStream());

        $stream = legacyStream([['role' => 'user', 'content' => 'What is a late fee?']], ['profile' => 'assistant'], 'cli.stream');

        $deltas = drain($stream);
        $final = $stream->getReturn();

        expect($deltas)->toBe(['The', ' fee', ' is', ' 1.5%.'])
            ->and($final)->toBeInstanceOf(ChatResponse::class)
            ->and($final->content)->toBe('The fee is 1.5%.')
            ->and($final->finishReason)->toBe(FinishReason::Stop)
            ->and($final->id)->toBe('chatcmpl-9f2a')
            ->and($final->model)->toBe('llama3.1:8b')
            ->and($final->usage->promptTokens)->toBe(68)
            ->and($final->usage->completionTokens)->toBe(19);
    });

    it('asks for a stream with usage included', function () {
        fakeStream(lateFeeStream());

        drain(legacyStream([['role' => 'user', 'content' => 'Hi']]));

        Http::assertSent(fn (Request $request) => $request['stream'] === true
            && $request['stream_options'] === ['include_usage' => true]
            && $request['temperature'] === 0.7
            && $request->url() === 'http://localhost:11434/v1/chat/completions');
    });

    it('dispatches the same ModelCalled event as chat()', function () {
        fakeStream(lateFeeStream());

        drain(legacyStream([['role' => 'user', 'content' => 'Hi']], feature: 'cli.stream'));

        $call = ModelCall::sole();

        expect($call->feature)->toBe('cli.stream')
            ->and($call->model)->toBe('llama3.1:8b')
            ->and($call->prompt_tokens)->toBe(68)
            ->and($call->completion_tokens)->toBe(19)
            ->and($call->finish_reason)->toBe('stop');
    });

    it('skips blank lines, comments and event names', function () {
        fakeStream(": keep-alive\n\nevent: message\ndata:".json_encode(deltaChunk('Hello'))."\n\n\n: ping\n\ndata: ".json_encode(deltaChunk(' there'))."\n\ndata: [DONE]\n\n");

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        expect(drain($stream))->toBe(['Hello', ' there'])
            ->and($stream->getReturn()->content)->toBe('Hello there');
    });

    it('handles CRLF line endings', function () {
        fakeStream('data: '.json_encode(deltaChunk('One'))."\r\n\r\ndata: [DONE]\r\n\r\n");

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        expect(drain($stream))->toBe(['One']);
    });

    it('fills in zero usage when the server omits it', function () {
        fakeStream(sse([deltaChunk('Hi'), ['id' => 'x', 'choices' => [['delta' => [], 'finish_reason' => 'stop']]]]));

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        drain($stream);

        expect($stream->getReturn()->usage->toArray())->toBe(['prompt_tokens' => 0, 'completion_tokens' => 0])
            ->and(ModelCall::sole()->prompt_tokens)->toBe(0);
    });

    it('reports finish_reason length from the last real chunk', function () {
        fakeStream(sse([deltaChunk('cut'), ['id' => 'x', 'choices' => [['delta' => [], 'finish_reason' => 'length']]]]));

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        drain($stream);

        expect($stream->getReturn()->finishReason)->toBe(FinishReason::Length);
    });

    it('throws StreamTruncated after yielding what it got when [DONE] never arrives', function () {
        fakeStream(lateFeeStream(done: false));

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']], feature: 'cli.stream');

        $seen = [];

        try {
            foreach ($stream as $delta) {
                $seen[] = $delta;
            }

            $this->fail('StreamTruncated was not thrown');
        } catch (StreamTruncated $e) {
            expect($seen)->toBe(['The', ' fee', ' is', ' 1.5%.'])
                ->and($e->content)->toBe('The fee is 1.5%.')
                ->and($e->finishReason)->toBe('stop');
        }

        expect(ModelCall::sole()->finish_reason)->toBe('truncated');
    });

    it('throws StreamTruncated for a stream that died mid-chunk', function () {
        fakeStream('data: '.json_encode(deltaChunk('Late'))."\n\ndata: {\"id\":\"x\",\"choi");

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        expect(fn () => drain($stream))->toThrow(StreamTruncated::class);
    });

    it('throws before yielding anything when the request itself fails', function () {
        Http::fake(['*/chat/completions' => Http::response(['error' => 'model not found'], 404)]);

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        expect(fn () => drain($stream))->toThrow(RequestException::class);

        Http::assertSentCount(1);
    });

    it('retries the opening request of a stream on a 503', function () {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push('', 503)
            ->push(lateFeeStream(), 200, ['Content-Type' => 'text/event-stream'])]);

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        expect(drain($stream))->toHaveCount(4);

        Http::assertSentCount(2);
    });
});

describe('streamed tool calls', function () {
    function toolChunk(array $fragments, ?string $finish = null, ?array $usage = null): array
    {
        return array_filter([
            'id' => 'chatcmpl-tc1',
            'model' => 'llama3.1:8b',
            'choices' => [['index' => 0, 'delta' => $fragments === [] ? [] : ['tool_calls' => $fragments], 'finish_reason' => $finish]],
            'usage' => $usage,
        ]);
    }

    function fragment(int $index, ?string $arguments, ?string $id = null, ?string $name = null): array
    {
        return array_filter([
            'index' => $index,
            'id' => $id,
            'function' => array_filter(['name' => $name, 'arguments' => $arguments], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);
    }

    it('accumulates argument fragments per index, even when two calls interleave', function () {
        fakeStream(sse([
            toolChunk([fragment(0, '', 'call_1', 'lookup_invoice')]),
            toolChunk([fragment(1, '', 'call_2', 'lookup_invoice')]),
            toolChunk([fragment(0, '{"month":')]),
            toolChunk([fragment(1, '{"month":"2026-04",')]),
            toolChunk([fragment(0, '"2026-03",'), fragment(1, '"invoice_number":null}')]),
            toolChunk([fragment(0, '"invoice_number":null}')]),
            toolChunk([], 'tool_calls', ['prompt_tokens' => 310, 'completion_tokens' => 52]),
        ]));

        $stream = legacyStream([['role' => 'user', 'content' => 'March and April?']]);

        $yielded = drain($stream);

        $final = $stream->getReturn();

        expect($yielded)->toHaveCount(2)
            ->and($yielded[0])->toBeInstanceOf(ToolCallStarted::class)
            ->and([$yielded[0]->name, $yielded[0]->index])->toBe(['lookup_invoice', 0])
            ->and([$yielded[1]->name, $yielded[1]->index])->toBe(['lookup_invoice', 1])
            ->and($final->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($final->content)->toBeNull()
            ->and($final->toolCalls)->toHaveCount(2)
            ->and($final->toolCalls[0]['id'])->toBe('call_1')
            ->and($final->toolCalls[0]['type'])->toBe('function')
            ->and(json_decode($final->toolCalls[0]['function']['arguments'], true))->toBe(['month' => '2026-03', 'invoice_number' => null])
            ->and($final->toolCalls[1]['id'])->toBe('call_2')
            ->and(json_decode($final->toolCalls[1]['function']['arguments'], true))->toBe(['month' => '2026-04', 'invoice_number' => null])
            ->and($final->usage->promptTokens)->toBe(310);
    });

    it('announces a tool once, the moment its name arrives, before the arguments are complete', function () {
        fakeStream(sse([
            toolChunk([fragment(0, '', 'call_1', 'lookup_invoice')]),
            toolChunk([fragment(0, '{"invoice_number":')]),
            toolChunk([fragment(0, '"4471","month":null}')]),
            toolChunk([], 'tool_calls'),
        ]));

        $stream = legacyStream([['role' => 'user', 'content' => 'Invoice 4471?']]);

        $stream->current();

        expect($stream->current())->toBeInstanceOf(ToolCallStarted::class);

        $stream->next();

        expect($stream->valid())->toBeFalse()
            ->and($stream->getReturn()->toolCalls[0]['function']['name'])->toBe('lookup_invoice');
    });

    it('still streams plain text and returns no tool calls', function () {
        fakeStream(lateFeeStream());

        $stream = legacyStream([['role' => 'user', 'content' => 'Hi']]);

        drain($stream);

        expect($stream->getReturn()->toolCalls)->toBe([]);
    });
});
