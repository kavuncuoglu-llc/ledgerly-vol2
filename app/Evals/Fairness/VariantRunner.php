<?php

namespace App\Evals\Fairness;

use App\Evals\CaseResult;
use App\Evals\GoldenCase;

interface VariantRunner
{
    public function run(GoldenCase $case): CaseResult;
}
