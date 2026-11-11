<?php

namespace App\Ai\Retrieval\Eval;

use App\Ai\AiManager;
use App\Ai\Embeddings\Embedder;
use InvalidArgumentException;

// Chapter 46, Topic 6: the tuned embedding model is served by the Python worker, not by Ollama, and its questions must be embedded by that same model (with its `search_query:` prefix), or the shadow vectors are compared with the wrong kind of vector.
// eval:retrieval has Python embed every golden question once, and this hands each one back to the retriever when it asks.
class PrecomputedEmbedder extends Embedder
{
    /** @param array<string, float[]> $vectors question => vector */
    public function __construct(AiManager $ai, string $model, private array $vectors)
    {
        parent::__construct($ai, $model, 0);
    }

    public function query(string $text): array
    {
        return $this->vectors[$text] ?? throw new InvalidArgumentException('No precomputed vector for the question: '.mb_strimwidth($text, 0, 60, '…'));
    }
}
