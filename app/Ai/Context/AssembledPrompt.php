<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\RetrievedChunk;

final readonly class AssembledPrompt
{
    /**
     * @param  array<string, int>  $breakdown  tokens per slot
     * @param  array<string, RetrievedChunk>  $labelMap  citation label => chunk, for this turn
     */
    public function __construct(public array $messages, public array $breakdown, public array $labelMap = []) {}

    public function labelMap(): array
    {
        return $this->labelMap;
    }

    public function tokens(): int
    {
        return array_sum($this->breakdown);
    }
}
