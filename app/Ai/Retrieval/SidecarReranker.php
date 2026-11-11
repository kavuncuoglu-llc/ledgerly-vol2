<?php

namespace App\Ai\Retrieval;

use App\Ai\Contracts\Reranker;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Sidecar\SidecarUnavailable;

final class SidecarReranker implements Reranker
{
    public function __construct(private SidecarClient $sidecar) {}

    public function score(string $question, array $passages): array
    {
        if ($passages === []) {
            return [];
        }

        $scores = $this->sidecar->json('/rerank', ['question' => $question, 'passages' => array_values($passages)])['scores'] ?? null;

        if (! is_array($scores) || count($scores) !== count($passages)) {
            throw new SidecarUnavailable('The sidecar returned the wrong number of scores.');
        }

        return array_map('floatval', array_values($scores));
    }
}
