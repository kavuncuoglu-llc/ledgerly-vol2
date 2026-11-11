<?php

namespace App\Ai\Hardware;

// memory needed = weights + (KV cache per token x context x slots) + headroom (Chapter 21, Figure 21-7). Every figure is in GiB, which the text calls GB, because memory is sold and reported that way.
final readonly class Sizing
{
    public function __construct(
        public float $weightsGb,
        public float $kvGb,
        public float $kvQ8Gb,
        public float $headroomGb,
        public float $totalGb,
        public string $cache,
        public int $context,
        public int $slots,
    ) {}

    public function spareGb(float $reachableGb): float
    {
        return $reachableGb - $this->totalGb;
    }

    public function fitsIn(float $reachableGb): bool
    {
        return $this->spareGb($reachableGb) >= 0;
    }
}
