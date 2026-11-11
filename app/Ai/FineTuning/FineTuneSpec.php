<?php

namespace App\Ai\FineTuning;

// Chapter 41, Topic 3: the few settings a hosted fine-tune exposes. The learning rate is a multiplier on the provider's own base rate; everything else about the training is the provider's choice.
final readonly class FineTuneSpec
{
    public function __construct(
        public string $baseModel,
        public string $method = 'supervised',
        public ?int $epochs = null,
        public ?float $learningRateMultiplier = null,
        public ?int $batchSize = null,
        public ?int $seed = null,
        public ?string $suffix = null,
    ) {}

    /** @return array<string, int|float> the settings that were set, in a stable order: they are part of the job's fingerprint */
    public function settings(): array
    {
        return array_filter([
            'epochs' => $this->epochs,
            'learning_rate_multiplier' => $this->learningRateMultiplier,
            'batch_size' => $this->batchSize,
            'seed' => $this->seed,
        ], fn ($value) => $value !== null);
    }
}
