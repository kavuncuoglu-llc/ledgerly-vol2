<?php

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\CircuitOpen;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Health\CircuitBreaker;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->breaker = app(CircuitBreaker::class);
});

function askLocal(string $feature = 'support.reply'): string
{
    return Ai::for($feature)->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], $feature))->content;
}

function chatFake(Closure|array $response): void
{
    Http::swap(new Factory);                                                             // a second fake in one test must replace the first, not sit behind it

    Http::fake(['*/chat/completions' => $response]);
}

function localIsDown(): void
{
    $GLOBALS['connectionAttempts'] = 0;

    chatFake(function () {
        $GLOBALS['connectionAttempts']++;

        throw new ConnectionException('Connection refused');
    });
}

it('opens after three consecutive failures and then refuses at once, without calling the server', function () {
    localIsDown();

    expect(fn () => askLocal())->toThrow(ConnectionException::class);                  // three attempts: Chapter 8's retry policy, each one counted

    expect($GLOBALS['connectionAttempts'])->toBe(3)
        ->and($this->breaker->state('local'))->toBe('open');

    expect(fn () => askLocal())->toThrow(CircuitOpen::class, 'local circuit open');

    expect($GLOBALS['connectionAttempts'])->toBe(3);                                      // the fourth call never left the machine
});

it('is not retried, not logged as a model call, and still a RequestException so existing handlers catch it', function () {
    localIsDown();

    try {
        askLocal();
    } catch (ConnectionException) {
    }

    $error = null;

    try {
        askLocal();
    } catch (Throwable $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(CircuitOpen::class)
        ->toBeInstanceOf(RequestException::class)
        ->and($error->retryable())->toBeFalse()
        ->and($error->provider)->toBe('local')
        ->and($error->response->status())->toBe(503)
        ->and($error->retryAfterSeconds())->toBe(60)
        ->and(ModelCall::count())->toBe(0);
});

it('counts ProviderUnavailable (5xx) and not a rate limit, a long prompt or a bad key', function () {
    chatFake(fn () => Http::response(['error' => 'busy'], 429));

    expect(fn () => askLocal())->toThrow(RateLimited::class)
        ->and($this->breaker->state('local'))->toBe('closed');

    chatFake(fn () => Http::response(['error' => ['message' => 'bad key']], 401));

    expect(fn () => askLocal())->toThrow(RequestException::class)
        ->and($this->breaker->state('local'))->toBe('closed');

    chatFake(fn () => Http::response(['error' => 'overloaded'], 503));

    expect(fn () => askLocal())->toThrow(ProviderUnavailable::class)
        ->and($this->breaker->state('local'))->toBe('open');
});

it('is reset by a success, so it is three in a row and not three in an hour', function () {
    config(['ai.breaker.threshold' => 3]);

    Http::fake(['*/chat/completions' => Http::sequence()
        ->push(['error' => 'x'], 503)->push(['error' => 'x'], 503)->push(chatBody('ok'))
        ->push(['error' => 'x'], 503)->push(['error' => 'x'], 503)->push(chatBody('ok again'))]);

    expect(askLocal())->toBe('ok')                                                       // two failures, then a success on the retry
        ->and($this->breaker->snapshot('local')['failures'])->toBe(0)
        ->and(askLocal())->toBe('ok again')
        ->and($this->breaker->state('local'))->toBe('closed');
});

it('lets one call through after sixty seconds, closes on a success and reopens at once on a failure', function () {
    localIsDown();

    try {
        askLocal();
    } catch (ConnectionException) {
    }

    $this->travel(61)->seconds();

    expect($this->breaker->state('local'))->toBe('half-open');

    chatFake(fn () => Http::response(chatBody('back')));

    expect(askLocal())->toBe('back')
        ->and($this->breaker->state('local'))->toBe('closed');

    localIsDown();

    try {
        askLocal();
    } catch (ConnectionException) {
    }

    $this->travel(61)->seconds();

    $attempts = 0;

    chatFake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('still down');
    });

    expect(fn () => askLocal())->toThrow(CircuitOpen::class)                           // the trial failed, so its own retries are refused: one request, not three
        ->and($attempts)->toBe(1)
        ->and($this->breaker->state('local'))->toBe('open');                              // one failure in half-open is enough: the count never went back to zero
});

it('opens on a stream that cannot be opened, and a stream refuses while it is open', function () {
    chatFake(fn () => throw new ConnectionException('Connection refused'));

    $open = fn () => iterator_to_array(Ai::chat('local')->stream(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'support.reply')), false);

    expect($open)->toThrow(ConnectionException::class)
        ->and($this->breaker->state('local'))->toBe('open')
        ->and($open)->toThrow(CircuitOpen::class);
});

it('keeps one breaker per provider name, so a dead frontier does not stop the local server', function () {
    config(['ai.providers.frontier.base_url' => 'https://frontier.example.com/v1', 'ai.providers.frontier.api_key' => 'sk-test', 'ai.providers.frontier.model' => 'm']);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'frontier')) {
            throw new ConnectionException('frontier is down');
        }

        return Http::response(chatBody('local is fine'));
    });

    try {
        Ai::chat('frontier')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'x'));
    } catch (ConnectionException) {
    }

    expect($this->breaker->state('frontier'))->toBe('open')
        ->and(askLocal())->toBe('local is fine');
});

it('describes itself the way ai:stats prints it', function () {
    localIsDown();

    $this->travelTo(now()->setTime(14, 6, 40));

    try {
        askLocal();
    } catch (ConnectionException) {
    }

    expect($this->breaker->describe('local'))->toBe('OPEN (3 consecutive ConnectionException at 14:06:40; retry at 14:07:40)');

    $this->travelTo(now()->setTime(14, 9, 52));

    chatFake(fn () => Http::response(chatBody('ok')));

    askLocal();

    expect($this->breaker->describe('local'))->toBe('closed (success at 14:09:52)');
});

it('does not take the model calls down with it when the cache is unreachable', function () {
    $broken = new class implements Repository
    {
        public function get($key, $default = null): mixed
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('cache down');
        }

        public function forget($key): bool
        {
            throw new RuntimeException('cache down');
        }

        public function has($key): bool
        {
            return false;
        }

        public function pull($key, $default = null): mixed
        {
            return $default;
        }

        public function add($key, $value, $ttl = null): bool
        {
            return false;
        }

        public function increment($key, $value = 1): int|bool
        {
            return false;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return false;
        }

        public function forever($key, $value): bool
        {
            return false;
        }

        public function remember($key, $ttl, Closure $callback): mixed
        {
            return $callback();
        }

        public function sear($key, Closure $callback): mixed
        {
            return $callback();
        }

        public function rememberForever($key, Closure $callback): mixed
        {
            return $callback();
        }

        public function getStore(): Store
        {
            throw new RuntimeException('cache down');
        }

        public function delete($key): bool
        {
            return false;
        }

        public function clear(): bool
        {
            return false;
        }

        public function getMultiple($keys, $default = null): iterable
        {
            return [];
        }

        public function setMultiple($values, $ttl = null): bool
        {
            return false;
        }

        public function deleteMultiple($keys): bool
        {
            return false;
        }

        public function set($key, $value, $ttl = null): bool
        {
            return false;
        }
    };

    app()->instance(CircuitBreaker::class, new CircuitBreaker($broken));
    app(AiManager::class)->forget();

    Http::fake(['*/chat/completions' => Http::response(chatBody('still answered'))]);

    expect(askLocal())->toBe('still answered');
});

it('routes support.reply to the named fallback while local is down, and nothing else', function () {
    config([
        'ai.fallback.local' => 'frontier',
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test',
        'ai.providers.frontier.model' => 'm',
    ]);

    Http::fake(function (Request $request) {
        return str_contains($request->url(), 'frontier')
            ? Http::response(chatBody('from the fallback'))
            : throw new ConnectionException('Connection refused');
    });

    try {
        askLocal();
    } catch (ConnectionException) {
    }

    expect(askLocal('support.reply'))->toBe('from the fallback')
        ->and(fn () => askLocal('conversation.summarize'))->toThrow(CircuitOpen::class);   // not a listed feature: it waits for the server instead of sending its text elsewhere
});

it('has no fallback by default, even with a frontier provider set up', function () {
    config(['ai.providers.frontier.base_url' => 'https://frontier.example.com/v1', 'ai.providers.frontier.api_key' => 'sk-test', 'ai.providers.frontier.model' => 'm']);

    localIsDown();

    try {
        askLocal();
    } catch (ConnectionException) {
    }

    expect(config('ai.fallback.local'))->toBeNull()
        ->and(fn () => askLocal())->toThrow(CircuitOpen::class);
});
