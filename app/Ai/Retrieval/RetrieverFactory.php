<?php

namespace App\Ai\Retrieval;

use App\Ai\AiManager;
use App\Ai\Cache\HelpVersion;
use App\Ai\Contracts\Reranker;
use App\Ai\Embeddings\Embedder;

// Builds the retriever the app uses: hybrid search, optionally widened by query rewriting, optionally narrowed by the reranker. retrieval:eval builds variations with overrides.
final class RetrieverFactory
{
    public function __construct(
        private Embedder $embedder,
        private HelpVersion $version,
        private AiManager $ai,
        private Reranker $reranker,
    ) {}

    public function stageOne(array $overrides = []): PgRetriever
    {
        return PgRetriever::fromConfig($this->embedder, $this->version)->with($overrides);
    }

    public function make(array $overrides = []): Retriever
    {
        $retriever = $this->stageOne(array_diff_key($overrides, array_flip(['rewrite', 'reranker'])));

        $rewrite = $overrides['rewrite'] ?? config('ai.retrieval.rewrite', 'off');

        $candidates = (int) ($overrides['pool'] ?? config('ai.retrieval.pool', 20));

        $chain = in_array($rewrite, ['rewrite', 'expand', 'hyde'], true)
            ? new RewritingRetriever($retriever, new QueryRewriter($this->ai, $rewrite), (int) config('ai.retrieval.rrf_k', 60))
            : $retriever;

        $reranker = $overrides['reranker'] ?? config('ai.retrieval.reranker', 'none');

        if ($reranker === 'none') {
            return $chain;
        }

        return new RerankingRetriever($chain, $this->reranker, $candidates, config('ai.retrieval.rerank_floor'));
    }
}
