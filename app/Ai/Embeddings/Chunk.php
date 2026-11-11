<?php

namespace App\Ai\Embeddings;

final readonly class Chunk
{
    public function __construct(
        public string $source,
        public int $position,
        public string $heading,
        public string $content,
        public int $tokens,
    ) {}
}
