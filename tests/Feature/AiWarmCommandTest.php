<?php

use App\Ai\Health\CircuitBreaker;
use App\Ai\Prompts\SupportPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

it('sends the support system message and a short question twice: the first pays the cost, the second is the one a customer gets', function () {
    Http::fake(['*/chat/completions' => Http::response(chatBody('ok', 'length', ['prompt_tokens' => 460, 'completion_tokens' => 1]))]);

    $this->artisan('ai:warm')
        ->expectsOutputToContain('llama3.1:8b: warm (')
        ->assertSuccessful();

    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request) => $request['messages'][0]['role'] === 'system'
        && $request['messages'][0]['content'] === SupportPrompt::system()                  // the same text the real flow sends, so the prefix cache is primed with it
        && $request['max_tokens'] === 1);
});

it('reports the system prompt\'s size and when it ran, and ai:doctor --health shows that time', function () {
    Http::fake(['*/chat/completions' => Http::response(chatBody('ok', 'length', ['prompt_tokens' => 460, 'completion_tokens' => 1]))]);

    $this->artisan('ai:warm')->expectsOutputToContain('prefix cached: system prompt (')->assertSuccessful();

    expect(Cache::get('ai:warm:local')['at'])->not->toBeNull();
});

it('closes an open breaker when the server answers, because a warm-up is a probe', function () {
    Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->recordFailure('local');
    }

    expect(app(CircuitBreaker::class)->state('local'))->toBe('open');

    $this->artisan('ai:warm')->assertSuccessful();

    expect(app(CircuitBreaker::class)->state('local'))->toBe('closed');
});

it('tries again while a server that just started is still loading, and fails when it never answers', function () {
    Http::fake(['*/chat/completions' => fn () => throw new ConnectionException('Connection refused')]);

    $this->artisan('ai:warm', ['--attempts' => 2])
        ->expectsOutputToContain('attempt 1 failed')
        ->expectsOutputToContain("Could not warm 'local'")
        ->assertFailed();

    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 10, 1);          // ten seconds between the two tries
});

it('refuses a provider that is not set up', function () {
    $this->artisan('ai:warm', ['--provider' => 'nonesuch'])->assertFailed();

    config(['ai.providers.local-70b.model' => null]);

    $this->artisan('ai:warm', ['--provider' => 'mid'])->expectsOutputToContain("The 'local-70b' provider is not set up")->assertFailed();
});
