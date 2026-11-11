<?php

use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function sseBody(bool $done = true): string
{
    $chunks = [
        ['id' => 'c1', 'model' => 'llama3.1:8b', 'choices' => [['delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]],
        ['id' => 'c1', 'model' => 'llama3.1:8b', 'choices' => [['delta' => ['content' => 'Late fees'], 'finish_reason' => null]]],
        ['id' => 'c1', 'model' => 'llama3.1:8b', 'choices' => [['delta' => ['content' => ' are charges.'], 'finish_reason' => null]]],
        ['id' => 'c1', 'model' => 'llama3.1:8b', 'choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 18, 'completion_tokens' => 142]],
    ];

    $body = collect($chunks)->map(fn ($chunk) => 'data: '.json_encode($chunk)."\n\n")->implode('');

    return $done
        ? $body."data: [DONE]\n\n"
        : $body;
}

it('prints the deltas and then the finish reason and token counts', function () {
    Http::fake(['*/chat/completions' => Http::response(sseBody(), 200, ['Content-Type' => 'text/event-stream'])]);

    $exitCode = Artisan::call('ai:stream', ['prompt' => 'Explain how late fees work.']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain("Late fees are charges.\n[stop · in 18 out 142]");

    Http::assertSent(fn (Request $request) => $request['stream'] === true
        && $request['messages'] === [['role' => 'user', 'content' => 'Explain how late fees work.']]);

    expect(ModelCall::sole()->feature)->toBe('cli.stream');
});

it('uses the profile you name', function () {
    Http::fake(['*/chat/completions' => Http::response(sseBody(), 200)]);

    $this->artisan('ai:stream', ['prompt' => 'Hi', '--profile' => 'deterministic'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['temperature'] === 0 && $request['seed'] === 42);
});

it('flags a stream that was cut off', function () {
    Http::fake(['*/chat/completions' => Http::response(sseBody(done: false), 200)]);

    $this->artisan('ai:stream', ['prompt' => 'Hi'])
        ->expectsOutputToContain('[stream cut off before completion]')
        ->assertFailed();

    expect(ModelCall::sole()->finish_reason)->toBe('truncated');
});

it('can print the time to the first delta and to [DONE]', function () {
    Http::fake(['*/chat/completions' => Http::response(sseBody(), 200)]);

    $this->artisan('ai:stream', ['prompt' => 'Hi', '--timing' => true])
        ->expectsOutputToContain('first delta after')
        ->assertSuccessful();
});

it('explains a server that is down', function () {
    Http::fake(['*/chat/completions' => Http::failedConnection()]);
    Sleep::fake();

    $this->artisan('ai:stream', ['prompt' => 'Hi'])
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();
});

it('explains a model that is not there', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'model not found'], 404)]);

    $this->artisan('ai:stream', ['prompt' => 'Hi'])
        ->expectsOutputToContain('answered 404')
        ->assertFailed();
});
