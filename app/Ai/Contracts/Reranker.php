<?php

namespace App\Ai\Contracts;

interface Reranker
{
    /**
     * @param  string[]  $passages
     * @return float[] one score per passage, same order; higher is more relevant
     */
    public function score(string $question, array $passages): array;
}
