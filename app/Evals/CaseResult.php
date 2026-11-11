<?php

namespace App\Evals;

final readonly class CaseResult
{
    public function __construct(
        public GoldenCase $case,
        public ?EvalAnswer $answer,
        public GateReport $report,
        public ?string $error = null,
    ) {}

    public function passedQuality(): bool
    {
        return $this->error === null
            && $this->report->passedQuality();
    }

    public function passedNever(): bool
    {
        return $this->report->passedNever();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->case->id,
            'source' => $this->case->source,
            'category' => $this->case->category,
            'passed_quality' => $this->passedQuality(),
            'passed_never' => $this->passedNever(),
            'reason' => $this->error ?? $this->report->describe(),
            'answer' => $this->answer?->toArray(),
        ];
    }
}
