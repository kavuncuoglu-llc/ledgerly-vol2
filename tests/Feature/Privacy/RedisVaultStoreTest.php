<?php

use App\Ai\Privacy\RedisVaultStore;
use App\Ai\Privacy\Span;
use App\Ai\Privacy\Vault;
use Illuminate\Support\Facades\Redis;

// The one test that wants Redis: what the vault keeps in production. It skips, and says so, where there is none.
function redisIsThere(): bool
{
    try {
        Redis::connection('default')->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

beforeEach(function () {
    $this->key = 'vault:test-'.bin2hex(random_bytes(4));

    $this->store = new RedisVaultStore('default');
})->group('privacy');

afterEach(function () {
    if (redisIsThere()) {
        Redis::connection('default')->del($this->key);
    }
});

it('keeps a value with a lifetime, hands it back and forgets it', function () {
    $this->store->put($this->key, 'sealed', 120);

    expect($this->store->get($this->key))->toBe('sealed')
        ->and(Redis::connection('default')->ttl($this->key))->toBeGreaterThan(0)->toBeLessThanOrEqual(120);

    $this->store->forget($this->key);

    expect($this->store->get($this->key))->toBeNull();
})->skip(fn () => ! redisIsThere(), 'Redis is not reachable');

it('holds a conversation\'s vault encrypted, so a reader of Redis sees no value', function () {
    $vault = new Vault($this->store, $this->key, 120);

    $text = 'dana@acme.example';

    $vault->tokenize($text, [new Span('EMAIL', 0, strlen($text), $text)]);

    expect(Redis::connection('default')->get($this->key))->not->toContain('dana')->not->toContain('EMAIL')
        ->and((new Vault($this->store, $this->key, 120))->restore('<EMAIL_1>'))->toBe('dana@acme.example');
})->skip(fn () => ! redisIsThere(), 'Redis is not reachable');
