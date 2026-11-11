<?php

namespace App\Ai\Contracts;

interface EmbeddingModel
{
    /**
     * @param  string[]  $texts
     * @return array<int, float[]> one vector per text, in order
     */
    public function embed(array $texts, EmbedKind $kind, ?string $model = null): array;

    public function dimensions(): int;

    public function model(): string;
}
