<?php

namespace App\Evals\Generators;

use App\Evals\GoldenCase;

interface Generator
{
    public function name(): string;

    /** @return iterable<GoldenCase> */
    public function generate(int $count): iterable;
}
