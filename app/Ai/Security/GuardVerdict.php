<?php

namespace App\Ai\Security;

final readonly class GuardVerdict
{
    public function __construct(
        public string $text,
        public ?string $blockedBy = null,
        public array $strippedHosts = [],
        public array $foreignIdentifiers = [],
    ) {}

    public function blocked(): bool
    {
        return $this->blockedBy !== null;
    }

    public function findings(): array
    {
        return array_values(array_filter([
            $this->blockedBy === null ? null : ['rule' => $this->blockedBy],
            $this->strippedHosts === [] ? null : ['rule' => 'unknown_domain_link', 'hosts' => $this->strippedHosts],
        ]));
    }
}
