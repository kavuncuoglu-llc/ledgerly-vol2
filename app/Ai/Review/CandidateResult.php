<?php

namespace App\Ai\Review;

// Chapter 50, Topic 9: what one watch-list candidate scored on the sample, beside the role it would replace.
final readonly class CandidateResult
{
    /** @param array<string, float|int|string|null> $metrics @param array<string, float|int|string|null> $baseline */
    public function __construct(
        public string $name,
        public string $model,
        public string $replaces,
        public array $metrics = [],
        public array $baseline = [],
        public ?string $skipped = null,
        public string $tripwire = 'not run: no lm_eval results for this candidate',
    ) {}

    public function line(): string
    {
        if ($this->skipped !== null) {
            return "{$this->name} ({$this->model}): not evaluated, {$this->skipped}";
        }

        return sprintf(
            '%s (%s): golden pass %s (%s %s) on %d cases · required facts %s · p50 %s · tripwire %s',
            $this->name,
            $this->model,
            $this->percent($this->metrics['golden_pass_rate'] ?? null),
            $this->replaces,
            $this->percent($this->baseline['golden_pass_rate'] ?? null),
            (int) ($this->metrics['cases'] ?? 0),
            $this->percent($this->metrics['required_facts_rate'] ?? null),
            isset($this->metrics['latency_p50_s']) ? $this->metrics['latency_p50_s'].' s' : '—',
            $this->tripwire,
        );
    }

    private function percent(mixed $rate): string
    {
        return $rate === null
            ? '—'
            : round((float) $rate * 100, 1).'%';
    }
}
