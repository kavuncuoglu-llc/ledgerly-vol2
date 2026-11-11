<?php

namespace App\Ai\Retrieval;

use App\Ai\Contracts\Reranker;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RerankingRetriever implements Retriever
{
    public function __construct(
        private Retriever $inner,
        private Reranker $reranker,
        private int $candidates = 20,
        private ?float $floor = null,
    ) {}

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $candidates = $this->inner->retrieve($question, $scope, max($k, $this->candidates));   // stage 1: wide

        if (count($candidates) <= $k) {
            return $candidates;                                                                  // nothing to rerank
        }

        try {
            $scores = $this->reranker->score($question, array_map(fn (RetrievedChunk $chunk) => $chunk->matchText(), $candidates));   // stage 2: precise
        } catch (Throwable $e) {
            Log::warning('Reranker unavailable; using the hybrid order', ['error' => $e->getMessage()]);

            return array_slice($candidates, 0, $k);                                              // the pipeline is one stage shorter, not broken
        }

        return $this->best($candidates, $scores, $k);
    }

    private function best(array $candidates, array $scores, int $k): array
    {
        $ranked = [];

        foreach ($candidates as $index => $chunk) {
            $ranked[] = ['chunk' => $chunk, 'score' => $scores[$index], 'index' => $index];
        }

        usort($ranked, fn (array $a, array $b) => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);   // ties keep the hybrid order

        if ($this->floor !== null) {
            $ranked = array_filter($ranked, fn (array $row) => $row['score'] >= $this->floor);
        }

        return array_map(fn (array $row) => $row['chunk'], array_slice(array_values($ranked), 0, $k));
    }
}
