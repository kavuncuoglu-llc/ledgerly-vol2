<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\EmbedKind;

final class RetryingEmbeddings implements EmbeddingModel
{
    public function __construct(private EmbeddingModel $inner) {}

    public function embed(array $texts, EmbedKind $kind, ?string $model = null): array
    {
        return Retries::run(fn () => $this->inner->embed($texts, $kind, $model));
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function model(): string
    {
        return $this->inner->model();
    }
}
