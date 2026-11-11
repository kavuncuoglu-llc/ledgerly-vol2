<?php

namespace App\Ai\Privacy;

// For a call with no conversation (a ticket classifier, an extraction) the vault lives as long as the call; and for tests, which never need Redis.
final class ArrayVaultStore implements VaultStore
{
    /** @var array<string, array{0: string, 1: int}> */
    private array $values = [];

    public function get(string $key): ?string
    {
        [$value, $expires] = $this->values[$key] ?? [null, 0];

        if ($value === null) {
            return null;
        }

        if ($expires < time()) {
            unset($this->values[$key]);

            return null;
        }

        return $value;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->values[$key] = [$value, time() + $ttlSeconds];
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->values);
    }
}
