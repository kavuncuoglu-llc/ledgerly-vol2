<?php

namespace App\Ai\Budgets;

use Illuminate\Support\Carbon;

// The same semantics as the two Lua scripts and the key commands, in memory, for tests and for a machine with no Redis. Not shared between processes: a real deployment uses RedisCounters.
final class ArrayCounters implements Counters
{
    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, int> */
    private array $expires = [];

    /** @var array<string, array{tokens: float, ts: float}> */
    private array $buckets = [];

    public function reserveAll(array $budgets, int $estimate): ?int
    {
        $position = 0;

        foreach ($budgets as $key => $limits) {
            if ($this->value($key) + $estimate > $limits['limit']) {
                return $position;
            }

            $position++;
        }

        foreach ($budgets as $key => $limits) {
            $this->values[$key] = (string) ($this->value($key) + $estimate);

            $this->expires[$key] ??= Carbon::now()->timestamp + $limits['ttl'];
        }

        return null;
    }

    public function adjust(string $key, int $delta, int $ttl): void
    {
        $this->values[$key] = (string) max(0, $this->value($key) + $delta);

        $this->expires[$key] ??= Carbon::now()->timestamp + $ttl;
    }

    public function value(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $this->values[$key] = $value;

        unset($this->expires[$key]);

        if ($ttl !== null) {
            $this->expires[$key] = Carbon::now()->timestamp + $ttl;
        }
    }

    public function get(string $key): ?string
    {
        if (isset($this->expires[$key]) && $this->expires[$key] <= Carbon::now()->timestamp) {
            unset($this->values[$key], $this->expires[$key]);
        }

        return $this->values[$key] ?? null;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key], $this->expires[$key]);
    }

    public function anyExists(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->get($key) !== null) {
                return true;
            }
        }

        return false;
    }

    public function keys(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->values), fn (string $key) => str_starts_with($key, $prefix) && $this->get($key) !== null));
    }

    public function takeFromBucket(string $key, float $capacity, float $refillPerSecond, float $weight): int
    {
        $now = (float) Carbon::now()->getPreciseTimestamp(6) / 1_000_000;

        $bucket = $this->buckets[$key] ?? ['tokens' => $capacity, 'ts' => $now];

        $tokens = min($capacity, $bucket['tokens'] + ($now - $bucket['ts']) * $refillPerSecond);

        if ($tokens < $weight) {
            return (int) ceil(($weight - $tokens) / $refillPerSecond * 1000);
        }

        $this->buckets[$key] = ['tokens' => $tokens - $weight, 'ts' => $now];

        return 0;
    }
}
