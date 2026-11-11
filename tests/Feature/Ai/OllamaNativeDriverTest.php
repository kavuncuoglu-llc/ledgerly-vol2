<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Drivers\OllamaNativeShape;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Http\StreamTruncated;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function nativeBody(string $content = 'A late fee is a charge.', array $extra = []): array
{
    return $extra + [
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => $content],
        'done' => true,
        'done_reason' => 'stop',
        'total_duration' => 2_840_000_000,
        'load_duration' => 31_200_000,
        'prompt_eval_count' => 38,
        'prompt_eval_duration' => 412_000_000,
        'eval_count' => 19,
        'eval_duration' => 1_390_000_000,
    ];
}

function ndjson(array ...$chunks): string
{
    return implode('', array_map(fn (array $chunk) => json_encode($chunk)."\n", $chunks));
}

function hi(): ChatRequest
{
    return ChatRequest::make([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 50, 'temperature' => 0], 'support.reply');
}

it('stays on /v1 until a context window or a keep-alive is asked for', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(chatBody('ok'))]);

    Ai::chat('local')->chat(hi());

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/chat/completions'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/chat'));
});

it('drops to /api/chat with num_ctx and keep_alive when the context window is overridden', function () {
    Http::fake(['*/api/chat' => Http::response(nativeBody())]);

    $response = Ai::chat('local')->withContextWindow(8192)->chat(hi());

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:11434/api/chat'
        && $request['stream'] === false
        && $request['options']['num_ctx'] === 8192
        && $request['options']['num_predict'] === 50
        && $request['options']['temperature'] === 0
        && $request['keep_alive'] === '30m'                                  // ai.ollama.keep_alive
        && $request['messages'] === [['role' => 'user', 'content' => 'hi']]);

    expect($response->content)->toBe('A late fee is a charge.')
        ->and($response->usage->promptTokens)->toBe(38)
        ->and($response->usage->completionTokens)->toBe(19);
});

it('logs the server counts and a measured time to first token', function () {
    Http::fake(['*/api/chat' => Http::response(nativeBody())]);

    Ai::chat('local')->withContextWindow(8192)->chat(hi());

    $call = ModelCall::sole();

    expect($call->provider)->toBe('local')
        ->and($call->prompt_tokens)->toBe(38)
        ->and($call->completion_tokens)->toBe(19)
        ->and($call->ttft_ms)->toBe(443);                                    // 31.2 ms to load + 412 ms to read the prompt
});

it('goes native for every request when the provider has a keep-alive', function () {
    config(['ai.providers.local.keep_alive' => '-1']);

    Http::fake(['*/api/chat' => Http::response(nativeBody())]);

    Ai::chat('local')->chat(hi());

    Http::assertSent(fn (Request $request) => $request['keep_alive'] === -1 && ! isset($request['options']['num_ctx']));      // -1 pins the model, as a number
});

it('turns a JSON schema into format, and OpenAI-shaped tool-call history into Ollama-shaped', function () {
    Http::fake(['*/api/chat' => Http::response(nativeBody('{"category":"billing"}'))]);

    $request = new ChatRequest(
        messages: [
            ['role' => 'user', 'content' => 'Where is INV-4471?'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"number":"INV-4471"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"status":"overdue"}'],
        ],
        feature: 'ticket.classify',
        tools: [['type' => 'function', 'function' => ['name' => 'lookup_invoice', 'parameters' => ['type' => 'object']]]],
        responseFormat: ['type' => 'json_schema', 'json_schema' => ['name' => 'ticket', 'schema' => ['type' => 'object', 'properties' => ['category' => ['type' => 'string']]]]],
    );

    Ai::chat('local')->withContextWindow(8192)->chat($request);

    Http::assertSent(fn (Request $sent) => $sent['format']['properties']['category']['type'] === 'string'
        && $sent['messages'][1]['tool_calls'][0]['function']['arguments'] === ['number' => 'INV-4471']
        && $sent['tools'][0]['function']['name'] === 'lookup_invoice');
});

it('reads a native tool call back as an OpenAI tool call with arguments as a JSON string', function () {
    $response = OllamaNativeShape::response(nativeBody('', ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'account_status', 'arguments' => new stdClass]]]]]));

    expect($response->content)->toBeNull()
        ->and($response->finishReason->value)->toBe('tool_calls')
        ->and($response->toolCalls[0]['type'])->toBe('function')
        ->and($response->toolCalls[0]['function'])->toBe(['name' => 'account_status', 'arguments' => '{}'])
        ->and($response->toolCalls[0]['id'])->toStartWith('call_');
});

it('splits an image content part into the images list', function () {
    Http::fake(['*/api/chat' => Http::response(nativeBody('{}'))]);

    $request = ChatRequest::make([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Read this'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,QUJD']]]]], [], 'invoice.extract');

    Ai::chat('local')->withContextWindow(8192)->chat($request);

    Http::assertSent(fn (Request $sent) => $sent['messages'][0]['content'] === 'Read this' && $sent['messages'][0]['images'] === ['QUJD']);
});

it('streams newline-delimited JSON as deltas and returns the whole reply', function () {
    Http::fake(['*/api/chat' => fn () => Http::response(ndjson(
        ['model' => 'llama3.1:8b', 'message' => ['role' => 'assistant', 'content' => 'A late '], 'done' => false],
        ['model' => 'llama3.1:8b', 'message' => ['role' => 'assistant', 'content' => 'fee.'], 'done' => false],
        nativeBody('', ['message' => ['role' => 'assistant', 'content' => '']]),
    ), 200, ['Content-Type' => 'application/x-ndjson'])]);

    $stream = Ai::chat('local')->withContextWindow(8192)->stream(hi());

    $text = '';

    foreach ($stream as $event) {
        if ($event instanceof Delta) {
            $text .= $event->text;
        }
    }

    $response = $stream->getReturn();

    expect($text)->toBe('A late fee.')
        ->and($response->content)->toBe('A late fee.')
        ->and($response->usage->promptTokens)->toBe(38)
        ->and($response->finishReason->value)->toBe('stop');

    Http::assertSent(fn (Request $request) => $request['stream'] === true && $request['options']['num_ctx'] === 8192);
});

it('streams a tool call as a started event with its arguments', function () {
    Http::fake(['*/api/chat' => fn () => Http::response(ndjson(
        ['model' => 'llama3.1:8b', 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'lookup_invoice', 'arguments' => ['number' => 'INV-4471']]]]], 'done' => false],
        nativeBody('', ['message' => ['role' => 'assistant', 'content' => '']]),
    ))]);

    $events = [];
    $stream = Ai::chat('local')->withContextWindow(8192)->stream(hi());

    foreach ($stream as $event) {
        $events[] = $event;
    }

    $response = $stream->getReturn();

    expect($events[0])->toBeInstanceOf(ToolCallStarted::class)
        ->and($events[0]->name)->toBe('lookup_invoice')
        ->and($events[1])->toBeInstanceOf(ToolCallArguments::class)
        ->and($events[1]->fragment)->toBe('{"number":"INV-4471"}')
        ->and($response->toolCalls[0]['function']['arguments'])->toBe('{"number":"INV-4471"}');
});

it('raises StreamTruncated when the native stream ends before done', function () {
    Http::fake(['*/api/chat' => fn () => Http::response(ndjson(['message' => ['role' => 'assistant', 'content' => 'A late'], 'done' => false]))]);

    $stream = Ai::chat('local')->withContextWindow(8192)->stream(hi());

    expect(function () use ($stream) {
        foreach ($stream as $event) {
            //
        }
    })->toThrow(StreamTruncated::class);
});

it('surfaces a native error the way /v1 errors surface', function () {
    Http::fake(['*/api/chat' => Http::response(['error' => 'model "nope" not found, try pulling it first'], 404)]);

    Ai::chat('local')->withContextWindow(8192)->chat(hi());
})->throws(InvalidRequest::class);
