<?php

namespace App\Ai\Retrieval;

interface Retriever
{
    /** @return RetrievedChunk[] ordered best-first, above threshold, at most $k */
    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array;
}
