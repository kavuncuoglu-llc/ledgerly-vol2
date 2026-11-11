<?php

use App\Models\ModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ai.base_url' => 'http://ollama.test:11434/v1',
        'ai.model' => 'llama3.1:8b',
        'ai.provider' => 'ollama',
    ]);
});

function completion(string $content, string $finish = 'stop', int $in = 24, int $out = 11): array
{
    return [
        'model' => 'llama3.1:8b',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
    ];
}

function nativeCompletion(string $content, string $finish = 'stop', int $in = 24, int $out = 11): array
{
    return [
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => $content],
        'done_reason' => $finish,
        'prompt_eval_count' => $in,
        'eval_count' => $out,
    ];
}

it('prints the completion, finish reason and token counts', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('Reminder: Invoice #4471 is past due'))]);

    $this->artisan('ai:sample', ['prompt' => 'Suggest a subject line.', '--temperature' => '0'])
        ->expectsOutputToContain('[1] Reminder: Invoice #4471 is past due')
        ->assertSuccessful();

    Artisan::call('ai:sample', ['prompt' => 'Suggest a subject line.', '--temperature' => '0', '--no-log' => true]);

    expect(Artisan::output())->toMatch('/^\[1\] Reminder: Invoice #4471 is past due\s+stop   · in 24  out 11$/m');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/chat/completions'
        && $request['temperature'] === 0.0
        && $request['stream'] === false
        && $request['model'] === 'llama3.1:8b'
        && $request['messages'] === [['role' => 'user', 'content' => 'Suggest a subject line.']]
        && ! isset($request['top_p']));
});

it('counts the distinct outputs across runs', function () {
    Http::fake(['*/v1/chat/completions' => Http::sequence()
        ->push(completion('A gentle nudge about March'))
        ->push(completion('Invoice #4471: we have not heard back'))
        ->push(completion('A gentle nudge about March'))]);

    $this->artisan('ai:sample', ['prompt' => 'Subject?', '--temperature' => '1.3', '--runs' => 3])
        ->expectsOutputToContain('[3] A gentle nudge about March')
        ->expectsOutputToContain('2 distinct output(s) in 3 runs')
        ->assertSuccessful();
});

it('logs every run to model_calls under the ai.sample feature', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('billing', 'stop', 31, 2))]);

    $this->artisan('ai:sample', ['prompt' => 'Classify', '--runs' => 2])->assertSuccessful();

    expect(ModelCall::where('feature', 'ai.sample')->count())->toBe(2)
        ->and(ModelCall::first()->prompt_tokens)->toBe(31)
        ->and(ModelCall::first()->completion_tokens)->toBe(2);
});

it('can skip the log', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('billing'))]);

    $this->artisan('ai:sample', ['prompt' => 'Classify', '--no-log' => true])->assertSuccessful();

    expect(ModelCall::count())->toBe(0);
});

it('turns the escaped stop sequence into a real one', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('billing', 'stop', 31, 2))]);

    $this->artisan('ai:sample', ['prompt' => 'Classify', '--temperature' => '0', '--stop' => ['\n', '.'], '--max-tokens' => '5'])
        ->expectsOutputToContain('[1] billing')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['stop'] === ["\n", '.'] && $request['max_tokens'] === 5);
});

it('shows a truncated answer as length', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('Late fees are charges added to an invoice when payment arrives after the due date. They are typically either a flat amount or a percentage of the outstanding balance, and the rate is', 'length', 18, 40))]);

    $this->artisan('ai:sample', ['prompt' => 'Explain how late fees work.', '--max-tokens' => '40'])
        ->expectsOutputToContain('length · in 18  out 40')
        ->assertSuccessful();

    expect(ModelCall::sole()->finish_reason)->toBe('length');
});

it('applies a profile and lets a flag override it', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('ok'))]);

    $this->artisan('ai:sample', ['prompt' => 'Hi', '--profile' => 'deterministic', '--seed' => '7'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['temperature'] === 0
        && $request['top_p'] === 1.0
        && $request['seed'] === 7);
});

it('goes native for the knobs the compatible endpoint cannot carry', function () {
    Http::fake(['*/api/chat' => Http::response(nativeCompletion('A fresh subject line', 'stop', 20, 6))]);

    $this->artisan('ai:sample', ['prompt' => 'Subject?', '--temperature' => '1.0', '--min-p' => '0.05', '--top-k' => '40', '--max-tokens' => '30', '--repeat-penalty' => '1.1'])
        ->expectsOutputToContain('[1] A fresh subject line')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/api/chat'
        && $request['options'] === ['temperature' => 1.0, 'top_k' => 40, 'min_p' => 0.05, 'repeat_penalty' => 1.1, 'num_predict' => 30]);

    expect(ModelCall::sole()->completion_tokens)->toBe(6);
});

it('warns when a knob is forced onto the compatible endpoint that ignores it', function () {
    Http::fake(['*/v1/chat/completions' => Http::response(completion('ok'))]);

    $this->artisan('ai:sample', ['prompt' => 'Hi', '--min-p' => '0.05', '--api' => 'openai'])
        ->expectsOutputToContain('has no field for: min_p')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => ! isset($request['min_p']));
});

it('rejects a profile that does not exist', function () {
    Http::fake();

    $this->artisan('ai:sample', ['prompt' => 'Hi', '--profile' => 'chaotic'])
        ->expectsOutputToContain("No sampling profile 'chaotic'")
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects an unknown api', function () {
    Http::fake();

    $this->artisan('ai:sample', ['prompt' => 'Hi', '--api' => 'grpc'])
        ->expectsOutputToContain("Unknown api 'grpc'")
        ->assertFailed();
});

it('reports a server error', function () {
    Http::fake(['*/v1/chat/completions' => Http::response('boom', 500)]);

    $this->artisan('ai:sample', ['prompt' => 'Hi'])
        ->expectsOutputToContain('The model server answered 500')
        ->assertFailed();
});

it('says so when the model server is not running', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('ai:sample', ['prompt' => 'Hi', '--runs' => 3])
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();

    expect(ModelCall::count())->toBe(0);
});
