<?php

namespace App\Ai\Retrieval\Eval;

final readonly class GoldenQuestion
{
    /**
     * @param  string[]  $articles  category/slug of the articles that answer it; empty means nothing should be retrieved
     * @param  string[]  $headings
     */
    public function __construct(
        public int $id,
        public string $question,
        public array $articles,
        public array $headings = [],
        public string $kind = 'plain',
    ) {}

    public function expectsNothing(): bool
    {
        return $this->articles === [];
    }
}
