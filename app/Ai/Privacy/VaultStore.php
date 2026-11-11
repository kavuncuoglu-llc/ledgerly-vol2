<?php

namespace App\Ai\Privacy;

interface VaultStore
{
    public function get(string $key): ?string;

    public function put(string $key, string $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}
