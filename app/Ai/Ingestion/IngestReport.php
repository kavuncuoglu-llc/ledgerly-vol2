<?php

namespace App\Ai\Ingestion;

final readonly class IngestReport
{
    public function __construct(
        public int $articles,
        public int $chunks,
        public int $averageTokens,
        public int $changed,
        public int $added,
        public int $removed,
        public int $unchanged,
        public int $embedded,
        public ?string $previousVersion,
        public string $version,
        public float $seconds = 0.0,
        public int $parents = 0,
        public int $children = 0,
    ) {}
}
