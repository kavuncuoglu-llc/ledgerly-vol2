<?php

namespace App\Ai\FineTuning;

// Chapter 41, Figure 41-4: what a provider reports about a job, in the app's own states (queued, running, succeeded, failed, cancelled).
final readonly class FineTuneStatus
{
    public function __construct(
        public string $state,
        public ?int $trainedTokens = null,
        public ?string $modelId = null,
        public ?string $error = null,
    ) {}
}
