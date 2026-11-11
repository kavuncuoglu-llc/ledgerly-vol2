<?php

namespace App\Evals\Sweep;

// Chapter 43, Topic 7: one run of a sweep as MLflow holds it, with the settings it was configured with and the latest value of each metric.
final class SweepRun
{
    public ?string $rejected = null;

    /** @param array<string, float|int|string> $params
     * @param array<string, float> $metrics */
    public function __construct(
        public string $id,
        public string $name,
        public array $params,
        public array $metrics,
        public bool $centerRepeat = false,
        public ?int $seed = null,
    ) {}

    public function metric(string ...$names): ?float
    {
        foreach ($names as $name) {
            if (isset($this->metrics[$name])) {
                return (float) $this->metrics[$name];
            }
        }

        return null;
    }

    public function scored(): bool
    {
        return $this->metric('golden_pass_rate') !== null;
    }

    public function validationLoss(): ?float
    {
        return $this->metric('eval_loss', 'val_loss', 'valid_loss');
    }

    public function tripwireDrop(): ?float
    {
        return $this->metric('tripwire_generation_largest_drop', 'tripwire_all_largest_drop', 'tripwire_largest_drop');
    }

    public function injection(): ?float
    {
        return $this->metric('injection_model_success_rate');
    }
}
