<?php

namespace App\Ai\Profile;

final readonly class MemorySample
{
    public function __construct(
        public float $at,                  // unix seconds
        public ?float $residentGb,         // memory held by the model server's processes
        public ?float $gpuGb,              // memory the GPU reports in use (unified memory: what Metal or amdgpu has mapped)
    ) {}
}
