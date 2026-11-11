<?php

namespace App\Ai\Budgets;

// Chapter 36, Topic 6: the few Redis operations the budgets need, all of them on keys that share the {ai-budget} hash tag. Tests bind an in-memory copy of the same semantics, so the suite needs no Redis;
// the Lua scripts themselves are run against a real Redis by a test that skips without one.
interface Counters
{
    /**
     * Reserve $estimate on every counter, or on none.
     *
     * @param  array<string, array{limit: int, ttl: int}>  $budgets  counter key => limit and period expiry (seconds)
     * @return int|null the 0-based position of the first full budget, null when everything was reserved
     */
    public function reserveAll(array $budgets, int $estimate): ?int;

    /** Add a (possibly negative) difference to a counter: settlement and release. A counter never goes below zero. */
    public function adjust(string $key, int $delta, int $ttl): void;

    public function value(string $key): int;

    public function set(string $key, string $value, ?int $ttl = null): void;

    public function get(string $key): ?string;

    public function forget(string $key): void;

    /** @param  string[]  $keys */
    public function anyExists(array $keys): bool;

    /** @return string[] keys under a prefix, for the kill switches and breakers a person can list */
    public function keys(string $prefix): array;

    /** @return int milliseconds to wait, 0 when the weight was taken */
    public function takeFromBucket(string $key, float $capacity, float $refillPerSecond, float $weight): int;
}
