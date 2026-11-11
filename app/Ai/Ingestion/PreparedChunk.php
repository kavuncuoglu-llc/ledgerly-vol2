<?php

namespace App\Ai\Ingestion;

use App\Ai\Embeddings\Chunk;
use Carbon\CarbonInterface;

final readonly class PreparedChunk
{
    public function __construct(
        public Chunk $chunk,
        public string $key,
        public string $title,
        public string $headingPath,
        public array $tags,
        public CarbonInterface $modifiedAt,
        public string $kind = 'parent',
        public ?string $parentKey = null,
        public ?string $contextHeader = null,
        public bool $embedded = true,
        public string $articleBody = '',
    ) {}

    public function embedText(?string $header): string
    {
        return $header === null || $header === ''
            ? $this->chunk->content
            : $header."\n\n".$this->chunk->content;
    }
}
