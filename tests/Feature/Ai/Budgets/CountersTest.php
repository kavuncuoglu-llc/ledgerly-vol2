<?php

use App\Ai\Budgets\ArrayCounters;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\RedisCounters;
use Illuminate\Support\Facades\Redis;

// Chapter 36, Topic 6: the two scripts of Figure 36-6, and the in-memory copy of the same rules the suite uses everywhere else. Every test here runs against both; the Redis ones skip when no Redis answers.
function redisAnswers(): bool
{
    try {
        return (string) Redis::connection()->ping() !== '';
    } catch (Throwable) {
        return false;
    }
}

function countersUnderTest(): array
{
    return [
        'memory' => fn () => [new ArrayCounters, '{ai-budget}:test:'.bin2hex(random_bytes(4)).':'],
        'redis' => fn () => [new RedisCounters, '{ai-budget}:test:'.bin2hex(random_bytes(4)).':'],
    ];
}

function cleanUp(Counters $counters, string $prefix): void
{
    foreach ($counters->keys($prefix) as $key) {
        $counters->forget($key);
    }
}

beforeEach(function () {
    if (str_contains(test()->name(), 'redis') && ! redisAnswers()) {
        test()->markTestSkipped('No Redis answers on the configured connection.');
    }
});

dataset('counters', countersUnderTest());

it('reserves the estimate on every counter, or on none', function (Closure $make) {
    [$counters, $p] = $make();

    $budgets = ["{$p}tenant" => ['limit' => 1_000, 'ttl' => 3600], "{$p}feature" => ['limit' => 500, 'ttl' => 3600], "{$p}app" => ['limit' => 10_000, 'ttl' => 3600]];

    expect($counters->reserveAll($budgets, 400))->toBeNull()
        ->and($counters->value("{$p}tenant"))->toBe(400)
        ->and($counters->value("{$p}feature"))->toBe(400)
        ->and($counters->value("{$p}app"))->toBe(400);

    expect($counters->reserveAll($budgets, 200))->toBe(1);                       // the feature budget (position 1) has 100 left: nothing at all was reserved

    expect($counters->value("{$p}tenant"))->toBe(400)
        ->and($counters->value("{$p}feature"))->toBe(400)
        ->and($counters->value("{$p}app"))->toBe(400);

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('reports the first full budget by position', function (Closure $make) {
    [$counters, $p] = $make();

    $counters->set("{$p}a", '95');

    $counters->set("{$p}b", '90');

    expect($counters->reserveAll(["{$p}a" => ['limit' => 100, 'ttl' => 60], "{$p}b" => ['limit' => 100, 'ttl' => 60], "{$p}c" => ['limit' => 100, 'ttl' => 60]], 5))->toBeNull();

    expect($counters->reserveAll(["{$p}c" => ['limit' => 100, 'ttl' => 60], "{$p}a" => ['limit' => 100, 'ttl' => 60], "{$p}b" => ['limit' => 100, 'ttl' => 60]], 1))->toBe(1);

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('lets a reservation of exactly what is left through, and refuses one unit more', function (Closure $make) {
    [$counters, $p] = $make();

    $counters->set("{$p}a", '90');

    expect($counters->reserveAll(["{$p}a" => ['limit' => 100, 'ttl' => 60]], 11))->toBe(0)
        ->and($counters->reserveAll(["{$p}a" => ['limit' => 100, 'ttl' => 60]], 10))->toBeNull()
        ->and($counters->value("{$p}a"))->toBe(100);

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('settles by the difference between the real cost and the estimate, and gives a failed call its reservation back', function (Closure $make) {
    [$counters, $p] = $make();

    $budget = ["{$p}a" => ['limit' => 10_000, 'ttl' => 60]];

    $counters->reserveAll($budget, 5_000);

    $counters->adjust("{$p}a", 3_200 - 5_000, 60);                              // the call cost 3,200

    expect($counters->value("{$p}a"))->toBe(3_200);

    $counters->reserveAll($budget, 1_000);

    $counters->adjust("{$p}a", -1_000, 60);                                     // the call failed

    expect($counters->value("{$p}a"))->toBe(3_200);

    $counters->adjust("{$p}a", -9_999, 60);                                     // a release after the period turned over cannot go below zero

    expect($counters->value("{$p}a"))->toBe(0);

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('sets the expiry of a period once, on the first reservation', function (Closure $make) {
    [$counters, $p] = $make();

    $counters->reserveAll(["{$p}a" => ['limit' => 10_000, 'ttl' => 2]], 10);

    $counters->reserveAll(["{$p}a" => ['limit' => 10_000, 'ttl' => 3_600]], 10);

    $this->travel(3)->seconds();

    if ($counters instanceof RedisCounters) {
        sleep(3);                                                                // a real Redis counts real seconds
    }

    expect($counters->value("{$p}a"))->toBe(0);                                  // the first ttl (2 s) held; the second reservation did not push it out

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('allows a burst up to the capacity, then says how long to wait', function (Closure $make) {
    [$counters, $p] = $make();

    expect($counters->takeFromBucket("{$p}bucket", 100, 10, 60))->toBe(0)       // 100 tokens, 10 a second: 60 taken
        ->and($counters->takeFromBucket("{$p}bucket", 100, 10, 30))->toBe(0);

    $wait = $counters->takeFromBucket("{$p}bucket", 100, 10, 50);               // 10 left, 40 short at 10 a second: about four seconds

    expect($wait)->toBeGreaterThan(3_000)->toBeLessThanOrEqual(4_100);

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('keeps flags and lists them by prefix', function (Closure $make) {
    [$counters, $p] = $make();

    $counters->set("{$p}kill:global", '{"reason":"x"}');

    $counters->set("{$p}kill:provider:frontier", '{"reason":"y"}');

    expect($counters->anyExists(["{$p}kill:feature:a", "{$p}kill:global"]))->toBeTrue()
        ->and($counters->anyExists(["{$p}kill:feature:a"]))->toBeFalse()
        ->and($counters->keys("{$p}kill:"))->toHaveCount(2);

    $counters->forget("{$p}kill:global");

    expect($counters->get("{$p}kill:global"))->toBeNull();

    cleanUp($counters, $p);
})->with('counters')->group('redis');

it('has the scripts the book names, as files', function () {
    expect(file_get_contents(resource_path('redis/reserve_all.lua')))->toContain("redis.call('INCRBY', key, est)")->toContain("'EXPIRE', key")
        ->and(file_get_contents(resource_path('redis/token_bucket.lua')))->toContain('math.ceil((w - tokens) / rate * 1000)');
});

it('reserves even when Redis has not loaded the script yet', function () {
    if (! redisAnswers()) {
        test()->markTestSkipped('No Redis answers on the configured connection.');
    }

    $counters = new RedisCounters;

    $prefix = '{ai-budget}:test:'.bin2hex(random_bytes(4)).':';

    Redis::connection()->client()->executeRaw(['SCRIPT', 'FLUSH']);

    expect($counters->reserveAll(["{$prefix}a" => ['limit' => 100, 'ttl' => 60]], 40))->toBeNull()
        ->and($counters->value("{$prefix}a"))->toBe(40)
        ->and($counters->reserveAll(["{$prefix}a" => ['limit' => 100, 'ttl' => 60]], 70))->toBe(0);

    cleanUp($counters, $prefix);
})->group('redis');
