<?php

namespace App\Ai\Contract;

// Chapter 47, Topic 8: one line of the contract suite: the check, the wire format it ran on, and what went wrong if it did.
final readonly class ContractResult
{
    public function __construct(
        public string $driver,
        public string $wire,
        public string $check,
        public bool $passed,
        public string $detail = '',
        public bool $gap = false,
    ) {}

    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'wire' => $this->wire,
            'check' => $this->check,
            'passed' => $this->passed,
            'detail' => $this->detail,
            'gap' => $this->gap,
        ];
    }
}
