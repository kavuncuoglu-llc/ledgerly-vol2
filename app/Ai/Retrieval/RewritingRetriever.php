<?php

namespace App\Ai\Retrieval;

final class RewritingRetriever implements Retriever
{
    public function __construct(private Retriever $inner, private QueryRewriter $rewriter, private int $rrfK = 60) {}

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $original = $this->inner->retrieve($question, $scope, $k);

        $variants = $this->rewriter->variants($question);

        if ($variants === []) {
            return $original;
        }

        $lists = [$original];                                                        // the original stays in the mix: a rewrite sometimes loses a detail

        foreach ($variants as $variant) {
            $lists[] = $this->inner->retrieve($variant, $scope, $k);
        }

        return $this->merge($lists, $k);
    }

    private function merge(array $lists, int $k): array
    {
        $scores = [];
        $chunks = [];

        foreach ($lists as $list) {
            foreach ($list as $rank => $chunk) {
                $scores[$chunk->id] = ($scores[$chunk->id] ?? 0.0) + 1 / ($this->rrfK + $rank + 1);
                $chunks[$chunk->id] ??= $chunk;
            }
        }

        arsort($scores);

        return array_map(fn (int $id) => $chunks[$id], array_slice(array_keys($scores), 0, $k));
    }
}
