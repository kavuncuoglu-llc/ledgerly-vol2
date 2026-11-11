<?php

namespace App\Ai\Health;

use App\Ai\Exceptions\ProviderUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

// Chapter 25, Topic 7: three consecutive "the server is not there" errors open the breaker for sixty seconds. While it is open nobody waits on a dead server: the router uses the fallback or the cache-only path.
// After the minute the next call is let through; a success closes the breaker, a failure opens it again at once. State lives in the cache store so every Horizon worker sees the same breaker.
final class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half-open';

    public function __construct(private Repository $cache) {}

    // What counts: the server did not answer (connection refused or timed out) or answered 408, 409, 425, 5xx. A 429, a 400 for a long prompt or a 401 is the caller's problem, not an outage.
    public static function counts(Throwable $e): bool
    {
        return $e instanceof ConnectionException || $e instanceof ProviderUnavailable;
    }

    public function isOpen(string $provider): bool
    {
        return $this->state($provider) === self::OPEN;
    }

    public function state(string $provider): string
    {
        $opened = $this->read($provider)['opened_at'];

        if ($opened === null) {
            return self::CLOSED;
        }

        return $this->now() < $opened + $this->openSeconds()
            ? self::OPEN
            : self::HALF_OPEN;
    }

    public function recordFailure(string $provider, string $reason = 'ProviderUnavailable'): void
    {
        $state = $this->read($provider);

        $state['failures']++;
        $state['last_failure_at'] = $this->now();
        $state['reason'] = $reason;

        if ($state['failures'] >= $this->threshold()) {
            $state['opened_at'] = $this->now();                                    // in half-open every failure reopens it: the failure count never went back to zero
        }

        $this->write($provider, $state);
    }

    public function recordSuccess(string $provider): void
    {
        $state = $this->read($provider);

        $recent = $state['last_success_at'] !== null && $this->now() - $state['last_success_at'] < 30;

        if ($state['failures'] === 0 && $state['opened_at'] === null && $recent) {
            return;                                                                // a healthy server: do not write the cache on every call
        }

        $this->write($provider, ['failures' => 0, 'opened_at' => null, 'last_failure_at' => $state['last_failure_at'], 'last_success_at' => $this->now(), 'reason' => $state['reason']]);
    }

    public function forget(string $provider): void
    {
        try {
            $this->cache->forget($this->key($provider));
        } catch (Throwable) {
            return;
        }
    }

    /** @return array{state: string, failures: int, opened_at: ?CarbonImmutable, retry_at: ?CarbonImmutable, last_failure_at: ?CarbonImmutable, last_success_at: ?CarbonImmutable, reason: ?string} */
    public function snapshot(string $provider): array
    {
        $state = $this->read($provider);

        return [
            'state' => $this->state($provider),
            'failures' => $state['failures'],
            'opened_at' => $this->at($state['opened_at']),
            'retry_at' => $this->at($state['opened_at'] === null ? null : $state['opened_at'] + $this->openSeconds()),
            'last_failure_at' => $this->at($state['last_failure_at']),
            'last_success_at' => $this->at($state['last_success_at']),
            'reason' => $state['reason'],
        ];
    }

    // The state as ai:stats and ai:doctor print it: closed (success at 14:09:52), OPEN (3 consecutive ProviderUnavailable at 14:06:40; retry at 14:07:40), half-open.
    public function describe(string $provider): string
    {
        $snapshot = $this->snapshot($provider);

        return match ($snapshot['state']) {
            self::OPEN => sprintf('OPEN (%d consecutive %s at %s; retry at %s)', $snapshot['failures'], $snapshot['reason'] ?? 'failures', $snapshot['opened_at']->format('H:i:s'), $snapshot['retry_at']->format('H:i:s')),
            self::HALF_OPEN => sprintf('half-open (the next call is a trial; it opened at %s)', $snapshot['opened_at']->format('H:i:s')),
            default => $snapshot['last_success_at'] === null
                ? 'closed'
                : sprintf('closed (success at %s)', $snapshot['last_success_at']->format('H:i:s')),
        };
    }

    public function threshold(): int
    {
        return max(1, (int) config('ai.breaker.threshold', 3));
    }

    public function openSeconds(): int
    {
        return max(1, (int) config('ai.breaker.open_seconds', 60));
    }

    // A cache that cannot be reached must not take the model calls down with it: the breaker then reads as closed and writes nothing.
    private function read(string $provider): array
    {
        try {
            $stored = (array) $this->cache->get($this->key($provider), []);
        } catch (Throwable) {
            $stored = [];
        }

        return array_replace(['failures' => 0, 'opened_at' => null, 'last_failure_at' => null, 'last_success_at' => null, 'reason' => null], $stored);
    }

    private function write(string $provider, array $state): void
    {
        try {
            $this->cache->put($this->key($provider), $state, 86400);
        } catch (Throwable) {
            return;
        }
    }

    private function key(string $provider): string
    {
        return "ai:breaker:{$provider}";
    }

    private function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }

    private function at(?int $timestamp): ?CarbonImmutable
    {
        return $timestamp === null
            ? null
            : CarbonImmutable::createFromTimestamp($timestamp);
    }
}
