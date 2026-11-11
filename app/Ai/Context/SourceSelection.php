<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\RetrievedChunk;

final readonly class SourceSelection
{
    /**
     * @param  RetrievedChunk[]  $kept
     * @param  RetrievedChunk[]  $dropped
     */
    public function __construct(public array $kept, public array $dropped, public int $tokens, public int $budget) {}

    public function droppedAny(): bool
    {
        return $this->dropped !== [];
    }
}
