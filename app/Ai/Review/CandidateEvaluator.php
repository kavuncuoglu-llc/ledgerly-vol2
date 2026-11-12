<?php

namespace App\Ai\Review;

interface CandidateEvaluator
{
    /** @param array<string, mixed> $candidate one entry of config('ai-watch.candidates') */
    public function evaluate(array $candidate, int $sample): CandidateResult;
}
