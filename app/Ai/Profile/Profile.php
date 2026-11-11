<?php

namespace App\Ai\Profile;

// The finished measurement: the per-second timeline and the numbers the summary line prints.
final readonly class Profile
{
    /** @param list<ProfileRow> $rows */
    public function __construct(
        public array $rows,
        public ?float $loadToFirstToken,
        public ?float $prefillRate,
        public ?float $decodeRate,
        public ?float $peakGb,
    ) {}
}
