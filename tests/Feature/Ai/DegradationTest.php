<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Health\CircuitBreaker;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Streaming\ReplyStarter;
use App\Ai\Support\Router;
use App\Ai\Support\SupportAssistant;
use App\Models\CachedAnswer;
use App\Models\Message;
use App\Models\ModelCall;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.semantic_cache.enabled' => true, 'ai.semantic_cache.require_context' => false]);

    [$this->user] = customerWithInvoice();

    $this->breaker = app(CircuitBreaker::class);
});

function openTheBreaker(string $provider = 'local'): void
{
    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->recordFailure($provider, 'ProviderUnavailable');
    }
}

function askOnTheQueue(string $question): Message
{
    test()->actingAs(test()->user);

    return app(ReplyStarter::class)->start(test()->user, $question)->refresh();
}

function chatRequests(): int
{
    return Http::recorded()->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/chat/completions'))->count();
}

it('tells the person the assistant is unavailable, without a failed job, and without calling the dead server', function () {
    Http::fake(['*' => fn () => throw new RuntimeException('nothing should be sent')]);

    openTheBreaker();

    $reply = askOnTheQueue('What is a late fee?');                                       // would throw out of a sync queue if the job failed

    expect($reply->status)->toBe('failed')
        ->and($reply->content)->toBe(SupportPrompt::unavailableMessage())
        ->and(Http::recorded())->toHaveCount(0);

    $call = ModelCall::sole();

    expect($call->model)->toBe('degraded')
        ->and($call->finish_reason)->toBe('unavailable')
        ->and(Message::withoutGlobalScopes()->where('conversation_id', $reply->conversation_id)->where('role', 'user')->count())->toBe(1);   // the question is stored once
});

it('never replays the notice to the model as its own earlier answer', function () {
    Http::fake(['*' => fn () => throw new RuntimeException('nothing should be sent')]);

    openTheBreaker();

    $reply = askOnTheQueue('What is a late fee?');

    $exchanges = app(ConversationStore::class)->history($reply->conversation, 4000);

    expect(collect($exchanges)->flatMap->toApiMessages()->pluck('content')->implode(' '))->not->toContain('briefly unavailable');
});

it('keeps answering a cached how-to question while the server is down: the cache-only path', function () {
    Http::fake(['*/embeddings' => fakeEmbeddingAngles(['How do I set up recurring invoices?' => 0]), '*/chat/completions' => fn () => throw new RuntimeException('nothing should be sent')]);

    CachedAnswer::create(['tenant_id' => null, 'category' => 'howto', 'question' => 'How do I set up recurring invoices?', 'answer' => 'Open the invoice, choose Make recurring, and set the interval.', 'help_version' => 'v1', 'status' => 'auto', 'embedding' => angledVector(0)]);

    openTheBreaker();

    $reply = askOnTheQueue('How do I set up recurring invoices?');

    expect($reply->status)->toBe('completed')
        ->and($reply->content)->toBe('Open the invoice, choose Make recurring, and set the interval.')
        ->and(chatRequests())->toBe(0);
});

it('hands a billing question to the named fallback while local is down', function () {
    config([
        'ai.fallback.local' => 'frontier',
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'example-model',
    ]);

    Http::fake([
        '*/embeddings' => fakeEmbeddingAngles([]),
        '*frontier.example.com/*' => streamReply(['From the ', 'fallback.']),
    ]);

    openTheBreaker();

    $reply = askOnTheQueue('Why is my March invoice marked late?');

    expect($reply->status)->toBe('completed')
        ->and($reply->content)->toBe('From the fallback.')
        ->and(ModelCall::where('feature', 'support.reply')->sole()->provider)->toBe('frontier');
});

it('answers the first customers of an outage with the notice, not a failed job, and opens the breaker', function () {
    Http::fake(['*/embeddings' => fakeEmbeddingAngles([]), '*/chat/completions' => Http::response(['error' => 'engine dead'], 500)]);

    $reply = askOnTheQueue('What is a late fee?');

    expect($reply->status)->toBe('failed')
        ->and($reply->content)->toBe(SupportPrompt::unavailableMessage())
        ->and($this->breaker->state('local'))->toBe('open')
        ->and(chatRequests())->toBe(3);

    $second = askOnTheQueue('And what is a grace period?');

    expect($second->content)->toBe(SupportPrompt::unavailableMessage())
        ->and(chatRequests())->toBe(3);                                                  // the second customer did not wait on the dead server
});

it('gives a command or a test that called the assistant directly the error, not the notice', function () {
    Http::fake(['*/embeddings' => fakeEmbeddingAngles([]), '*/chat/completions' => Http::response(['error' => 'engine dead'], 500)]);

    expect(fn () => app(SupportAssistant::class)->answer($this->user, 'What is a late fee?'))->toThrow(ProviderUnavailable::class);
});

it('recovers: after a minute one trial goes through, the breaker closes, and the next replies are normal', function () {
    Http::fake(['*/embeddings' => fakeEmbeddingAngles([]), '*/chat/completions' => Http::response(['error' => 'engine dead'], 500)]);

    askOnTheQueue('What is a late fee?');

    expect($this->breaker->state('local'))->toBe('open');

    $this->travel(61)->seconds();

    Http::swap(new Factory);
    Http::fake(['*/embeddings' => fakeEmbeddingAngles([]), '*/chat/completions' => streamReply(['Fees start after a grace period.'])]);

    $reply = askOnTheQueue('What is a late fee?');

    expect($reply->status)->toBe('completed')
        ->and($reply->content)->toBe('Fees start after a grace period.')
        ->and($this->breaker->state('local'))->toBe('closed');
});

it('shows the breaker and where the requests went in ai:stats', function () {
    Http::fake(['*/embeddings' => fakeEmbeddingAngles(['How do I set up recurring invoices?' => 0]), '*/chat/completions' => fn () => throw new RuntimeException('nothing should be sent')]);

    CachedAnswer::create(['tenant_id' => null, 'category' => 'howto', 'question' => 'How do I set up recurring invoices?', 'answer' => 'Open the invoice.', 'help_version' => 'v1', 'status' => 'auto', 'embedding' => angledVector(0)]);

    openTheBreaker();

    askOnTheQueue('How do I set up recurring invoices?');
    askOnTheQueue('Why is my March invoice marked late?');
    askOnTheQueue('And what about the one before?');

    $this->artisan('ai:stats', ['--feature' => 'support.reply', '--since' => '2m'])
        ->expectsOutputToContain('breaker: OPEN (3 consecutive ProviderUnavailable at ')
        ->expectsOutputToContain('routed: local 0 · cache-only 1 · degraded-message 2 · failed jobs 0')
        ->assertSuccessful();
});

it('says closed when nothing has gone wrong', function () {
    $this->artisan('ai:stats', ['--feature' => 'support.reply'])
        ->expectsOutputToContain('breaker: closed')
        ->assertSuccessful();
});

it('tells the router where the work goes: local, the named fallback, or the cache-only path', function () {
    $router = app(Router::class);

    expect($router->available())->toBe('local');

    openTheBreaker();

    expect($router->available())->toBe('cache-only');

    config([
        'ai.fallback.local' => 'frontier',
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test',
        'ai.providers.frontier.model' => 'm',
    ]);

    expect($router->available())->toBe('frontier')
        ->and($router->available('conversation.summarize'))->toBe('cache-only');          // a feature that is not listed for fallback

    openTheBreaker('frontier');

    expect($router->available())->toBe('cache-only');                                       // the fallback is down too
});
