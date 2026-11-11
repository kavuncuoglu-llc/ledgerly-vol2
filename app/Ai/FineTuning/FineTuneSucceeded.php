<?php

namespace App\Ai\FineTuning;

use App\Models\FineTuneJob;

final readonly class FineTuneSucceeded
{
    public function __construct(public FineTuneJob $job) {}
}
