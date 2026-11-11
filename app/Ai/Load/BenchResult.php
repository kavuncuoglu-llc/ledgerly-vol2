<?php

namespace App\Ai\Load;

final readonly class BenchResult
{
    public function __construct(
        public int $concurrency,
        public int $requests,
        public int $failed,
        public float $requestsPerSecond,
        public float $outputTokensPerSecond,
        public float $ttftP50Ms,
        public float $ttftP99Ms,
        public float $itlP50Ms,
        public float $itlP99Ms,
    ) {}
}
