<?php

namespace App\Ai\Profile;

final readonly class ProfileRow
{
    public function __construct(
        public int $second,
        public ?float $residentGb,
        public ?float $gpuGb,
        public string $phase,
        public ?float $prefillRate,
        public ?float $decodeRate,
        public int $contextTokens,
    ) {}
}
