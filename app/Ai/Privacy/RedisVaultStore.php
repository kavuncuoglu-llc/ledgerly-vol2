<?php

namespace App\Ai\Privacy;

use Illuminate\Support\Facades\Redis;

// What the vault keeps in production: one encrypted value per conversation, with a lifetime.
final class RedisVaultStore implements VaultStore
{
    public function __construct(private string $connection = 'default') {}

    public function get(string $key): ?string
    {
        $value = Redis::connection($this->connection)->get($key);

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        Redis::connection($this->connection)->setex($key, $ttlSeconds, $value);
    }

    public function forget(string $key): void
    {
        Redis::connection($this->connection)->del($key);
    }
}
