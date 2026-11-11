<?php

namespace App\Ai\Embeddings;

use App\Ai\AiManager;
use App\Ai\Contracts\EmbedKind;

// Asks the EmbeddingModel behind the 'local-embed' provider (Chapter 19); the nomic prefixes live in that provider's config, because they belong to the model, not to the caller.
final class Embedder
{
    public function __construct(private AiManager $ai, private string $model, private ?int $dimensions = null) {}      // null = AI_EMBED_DIMENSIONS, 0 = accept any width (candidate models in retrieval:eval)

    public function model(): string
    {
        return $this->model;
    }

    /** @return float[] */
    public function query(string $text): array
    {
        return $this->embed([$text], EmbedKind::Query)[0];
    }

    /** @return float[] */
    public function document(string $text): array
    {
        return $this->embed([$text], EmbedKind::Document)[0];
    }

    /**
     * One HTTP call for many passages (Chapter 18, Topic 6): the endpoint accepts an array, so 48 chunks cost one round trip instead of 48.
     *
     * @param  string[]  $texts
     * @return array<int, float[]> in the order of $texts
     */
    public function documents(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return $this->embed($texts, EmbedKind::Document);
    }

    /** @return array<int, float[]> */
    private function embed(array $texts, EmbedKind $kind): array
    {
        $vectors = $this->ai->embed()->embed($texts, $kind, $this->model);

        return array_map(fn (array $vector) => $this->checked($vector), $vectors);
    }

    private function checked(array $vector): array
    {
        $expected = $this->dimensions ?? (int) config('ai.embed_dimensions');

        if ($expected !== 0 && count($vector) !== $expected) {
            throw new EmbeddingFailed(sprintf(
                "Model '%s' returned %d dimensions, but AI_EMBED_DIMENSIONS and the help_chunks.embedding column are %d. Use a %d-dimension model or migrate the column and re-embed.",
                $this->model,
                count($vector),
                $expected,
                $expected,
            ));
        }

        return $vector;
    }
}
