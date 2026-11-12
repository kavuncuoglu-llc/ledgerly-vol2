<?php

namespace App\Evals\Fairness;

use App\Evals\CaseResult;
use App\Evals\GateRunner;
use App\Evals\GoldenCase;

// Chapter 51, Topic 3: a variant goes through the whole pipeline and the gates exactly as a golden case does (Chapter 29).
final class GateVariantRunner implements VariantRunner
{
    public function __construct(private GateRunner $runner) {}

    public function run(GoldenCase $case): CaseResult
    {
        return $this->runner->one($case, false);
    }
}
