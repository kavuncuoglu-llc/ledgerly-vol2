<?php

namespace App\Ai\Budgets;

use Illuminate\Support\Facades\Redis;
use Predis\ClientInterface;
use RuntimeException;
use Throwable;

// Raw commands, as RedisStreams does: the same key text reaches Redis whichever client Laravel was given, and no connection prefix is added to a key whose hash tag the scripts depend on.
final class RedisCounters implements Counters
{
    private const SCRIPTS = ['reserve_all', 'token_bucket'];

    /** @var array<string, string> */
    private static array $shas = [];

    public function __construct(private string $connection = 'default') {}

    public static function fromConfig(): self
    {
        return new self((string) config('ai.budgets.redis_connection', 'default'));
    }

    public function reserveAll(array $budgets, int $estimate): ?int
    {
        $arguments = [(string) $estimate];

        foreach ($budgets as $limits) {
            array_push($arguments, (string) $limits['limit'], (string) $limits['ttl']);
        }

        $full = (int) $this->script('reserve_all', array_keys($budgets), $arguments);

        return $full === 0
            ? null
            : $full - 1;
    }

    public function adjust(string $key, int $delta, int $ttl): void
    {
        if ($delta === 0) {
            return;
        }

        $value = (int) $this->command(['INCRBY', $key, (string) $delta]);

        if ($value < 0) {
            $this->command(['SET', $key, '0', 'KEEPTTL']);                       // a release after the period turned over: nothing to give back
        }

        $this->command(['EXPIRE', $key, (string) $ttl, 'NX']);
    }

    public function value(string $key): int
    {
        return (int) $this->command(['GET', $key]);
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $arguments = ['SET', $key, $value];

        if ($ttl !== null) {
            array_push($arguments, 'EX', (string) $ttl);
        }

        $this->command($arguments);
    }

    public function get(string $key): ?string
    {
        $value = $this->command(['GET', $key]);

        return $value === null || $value === false
            ? null
            : (string) $value;
    }

    public function forget(string $key): void
    {
        $this->command(['DEL', $key]);
    }

    public function anyExists(array $keys): bool
    {
        return (int) $this->command(['EXISTS', ...$keys]) > 0;
    }

    public function keys(string $prefix): array
    {
        $found = [];

        $cursor = '0';

        do {
            [$cursor, $batch] = $this->command(['SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '200']);

            array_push($found, ...array_map('strval', $batch));
        } while ((string) $cursor !== '0');

        return array_values(array_unique($found));
    }

    public function takeFromBucket(string $key, float $capacity, float $refillPerSecond, float $weight): int
    {
        return (int) $this->script('token_bucket', [$key], [(string) $capacity, (string) $refillPerSecond, (string) $weight]);
    }

    // EVALSHA with a fallback to EVAL: the script is loaded once per process, and Redis may have been restarted since.
    private function script(string $name, array $keys, array $arguments): mixed
    {
        $source = (string) file_get_contents(resource_path("redis/{$name}.lua"));

        $sha = self::$shas[$name] ??= sha1($source);

        try {
            return $this->command(['EVALSHA', $sha, (string) count($keys), ...$keys, ...$arguments]);
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }
        }

        return $this->command(['EVAL', $source, (string) count($keys), ...$keys, ...$arguments]);
    }

    private function command(array $arguments): mixed
    {
        $client = Redis::connection($this->connection)->client();

        if (! $client instanceof ClientInterface) {
            return $client->rawCommand(...$arguments);
        }

        $reply = $client->executeRaw($arguments, $failed);

        if ($failed) {
            throw new RuntimeException((string) $reply);                              // Predis hands an error back as its text and sets the flag, it does not throw
        }

        return $reply;
    }
}
