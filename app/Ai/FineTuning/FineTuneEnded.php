<?php

namespace App\Ai\FineTuning;

use App\Models\FineTuneJob;

final readonly class FineTuneEnded
{
    public function __construct(public FineTuneJob $job) {}
}
