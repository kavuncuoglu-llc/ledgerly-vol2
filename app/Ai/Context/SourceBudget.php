<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Tokens\TokenCounter;

// Chapter 35, Topic 4: retrieved sources are added best first until the budget is spent, so a bigger top-k or a longer chunk can never inflate the prompt past it.
// A chunk that does not fit is skipped, not the end of the list: a smaller one further down may still fit.
final class SourceBudget
{
    public function __construct(private TokenCounter $counter) {}

    /** @param  RetrievedChunk[]  $rankedChunks  best first, after reranking */
    public function fit(array $rankedChunks, ?int $budget = null): SourceSelection
    {
        $budget ??= (int) config('ai.context.sources_max_tokens', 3_000);

        $kept = [];

        $dropped = [];

        $used = 0;

        foreach ($rankedChunks as $chunk) {
            $cost = $this->counter->count($chunk->body());

            if ($used + $cost > $budget) {
                $dropped[] = $chunk;

                continue;
            }

            $kept[] = $chunk;

            $used += $cost;
        }

        return new SourceSelection($kept, $dropped, $used, $budget);
    }
}
