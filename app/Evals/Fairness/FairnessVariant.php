<?php

namespace App\Evals\Fairness;

use App\Evals\GoldenCase;

// Chapter 51, Topic 3: one copy of a base case that differs from it in one detail only. The control is the case as written.
final readonly class FairnessVariant
{
    public const CONTROL = 'control';

    public function __construct(
        public GoldenCase $case,
        public string $baseId,
        public string $dimension,
        public string $level,
    ) {}
}
