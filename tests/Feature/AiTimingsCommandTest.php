<?php

use App\Models\ModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ai.base_url' => 'http://ollama.test:11434/v1',
        'ai.model' => 'llama3.1:8b',
        'ai.provider' => 'ollama',
    ]);
});

function nativeChatResponse(): array
{
    return [
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => 'A late fee is a charge added when an invoice is paid after its due date.'],
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

it('prints the native timing breakdown and logs the call', function () {
    Http::fake(['ollama.test:11434/api/chat' => Http::response(nativeChatResponse())]);

    $this->artisan('ai:timings', ['prompt' => 'In one sentence, what is a late fee?'])
        ->expectsOutputToContain('total duration:       2.84s')
        ->expectsOutputToContain('prompt eval rate:     92.2 tokens/s')
        ->expectsOutputToContain('eval rate:            13.7 tokens/s')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/api/chat'
        && $request['model'] === 'llama3.1:8b'
        && $request['stream'] === false
        && $request['messages'][0]['content'] === 'In one sentence, what is a late fee?');

    $call = ModelCall::sole();

    expect($call->feature)->toBe('ai.timings')
        ->and($call->provider)->toBe('ollama')
        ->and($call->prompt_tokens)->toBe(38)
        ->and($call->completion_tokens)->toBe(19)
        ->and($call->latency_ms)->toBe(2840)
        ->and($call->finish_reason)->toBe('stop');
});

it('prints the reply when asked', function () {
    Http::fake(['ollama.test:11434/api/chat' => Http::response(nativeChatResponse())]);

    $this->artisan('ai:timings', ['prompt' => 'What is a late fee?', '--show' => true])
        ->expectsOutputToContain('A late fee is a charge added')
        ->assertSuccessful();
});

it('uses a help article as the long prompt', function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', body: "## How the fee is calculated\n\nA fee of 0.05% a day."),
    ]);
    Http::fake(['ollama.test:11434/api/chat' => Http::response(nativeChatResponse())]);

    $this->artisan('ai:timings', ['--article' => 'billing/late-fees'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'A fee of 0.05% a day.')
        && str_ends_with($request['messages'][0]['content'], 'Summarize this in one sentence.'));
});

it('fails on a help article that does not exist', function () {
    helpLibrary([]);
    Http::fake();

    $this->artisan('ai:timings', ['--article' => 'billing/nope'])->assertFailed();

    Http::assertNothingSent();
});

it('runs the prompt several times so the second run shows the load time disappear', function () {
    Http::fake(['ollama.test:11434/api/chat' => Http::sequence()
        ->push(nativeChatResponse())
        ->push([...nativeChatResponse(), 'load_duration' => 1_200_000])]);

    $this->artisan('ai:timings', ['prompt' => 'Hello', '--repeat' => 2, '--no-log' => true])
        ->expectsOutputToContain('Run 1:')
        ->expectsOutputToContain('Run 2:')
        ->expectsOutputToContain('load duration:        1.2ms')
        ->assertSuccessful();

    expect(ModelCall::count())->toBe(0);
});

it('falls back to the OpenAI-compatible endpoint when the server has no native API', function () {
    Http::fake([
        'ollama.test:11434/api/chat' => Http::response('not found', 404),
        'ollama.test:11434/v1/chat/completions' => Http::response([
            'model' => 'llama3.1:8b',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'A charge.'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]),
    ]);

    $this->artisan('ai:timings', ['prompt' => 'What is a late fee?'])
        ->expectsOutputToContain('via openai API')
        ->expectsOutputToContain('prompt eval count:    12 token(s)')
        ->expectsOutputToContain('n/a (no prefill timing on this endpoint)')
        ->assertSuccessful();

    expect(ModelCall::sole()->finish_reason)->toBe('length');
});

it('can be forced onto the OpenAI-compatible endpoint', function () {
    Http::fake(['ollama.test:11434/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
    ])]);

    $this->artisan('ai:timings', ['prompt' => 'Hi', '--api' => 'openai'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer ollama'));
});

it('reports a server error instead of a stack trace', function () {
    Http::fake(['ollama.test:11434/api/chat' => Http::response('model not found', 500)]);

    $this->artisan('ai:timings', ['prompt' => 'Hi'])
        ->expectsOutputToContain('The model server answered 500')
        ->assertFailed();
});

it('says so when the model server is not running', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('ai:timings', ['prompt' => 'Hi'])
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();

    expect(ModelCall::count())->toBe(0);
});

it('needs something to send', function () {
    Http::fake();

    $this->artisan('ai:timings')->assertFailed();

    Http::assertNothingSent();
});
