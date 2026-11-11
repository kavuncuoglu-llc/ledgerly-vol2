<?php

namespace App\Ai\Privacy;

final class VaultFactory
{
    public function __construct(
        private VaultStore $store,
        private int $ttlSeconds = 604800,
    ) {}

    public static function fromConfig(VaultStore $store): self
    {
        return new self($store, (int) config('ai.privacy.vault.ttl_days', 7) * 86400);
    }

    // A conversation's vault is shared by every call in it, whichever worker makes the call. A call with no conversation gets a vault that dies with it.
    public function for(int|string|null $conversationId): Vault
    {
        if ($conversationId === null) {
            return new Vault(new ArrayVaultStore, 'vault:ephemeral', $this->ttlSeconds);
        }

        return new Vault($this->store, Vault::keyFor($conversationId), $this->ttlSeconds);
    }

    public function forget(int|string $conversationId): void
    {
        $this->store->forget(Vault::keyFor($conversationId));
    }
}
