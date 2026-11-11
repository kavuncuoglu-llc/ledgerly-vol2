<?php

namespace App\Evals;

use Illuminate\Support\Collection;

// The two thresholds of Chapter 29, Topic 5: quality gates are judged by pass rate, never-gates allow nothing.
final readonly class GateSummary
{
    /** @param Collection<int, CaseResult> $results */
    public function __construct(
        public Collection $results,
        public float $qualityThreshold,
        public int $neverAllowed,
    ) {}

    public function total(): int
    {
        return $this->results->count();
    }

    public function qualityPasses(): int
    {
        return $this->results->filter(fn (CaseResult $result) => $result->passedQuality())->count();
    }

    public function neverPasses(): int
    {
        return $this->results->filter(fn (CaseResult $result) => $result->passedNever())->count();
    }

    public function qualityRate(): float
    {
        return $this->total() === 0
            ? 0.0
            : $this->qualityPasses() / $this->total();
    }

    public function neverFailures(): int
    {
        return $this->total() - $this->neverPasses();
    }

    public function qualityOk(): bool
    {
        return $this->total() > 0
            && $this->qualityRate() >= $this->qualityThreshold;
    }

    public function neverOk(): bool
    {
        return $this->neverFailures() <= $this->neverAllowed;
    }

    public function passed(): bool
    {
        return $this->qualityOk()
            && $this->neverOk();
    }

    /** @return Collection<int, CaseResult> */
    public function failing(): Collection
    {
        return $this->results
            ->reject(fn (CaseResult $result) => $result->passedQuality() && $result->passedNever())
            ->values();
    }

    public function bySource(): array
    {
        return $this->results
            ->groupBy(fn (CaseResult $result) => $result->case->source)
            ->map(fn (Collection $group) => [
                'cases' => $group->count(),
                'quality_pass_rate' => round($group->filter(fn (CaseResult $result) => $result->passedQuality())->count() / $group->count(), 4),
            ])
            ->all();
    }

    public function byCategory(): array
    {
        return $this->results
            ->groupBy(fn (CaseResult $result) => $result->case->category)
            ->map(fn (Collection $group) => [
                'cases' => $group->count(),
                'quality_pass_rate' => round($group->filter(fn (CaseResult $result) => $result->passedQuality())->count() / $group->count(), 4),
            ])
            ->all();
    }

    public function toArray(): array
    {
        return [
            'cases' => $this->total(),
            'quality_pass_rate' => round($this->qualityRate(), 4),
            'quality_threshold' => $this->qualityThreshold,
            'never_failures' => $this->neverFailures(),
            'never_allowed' => $this->neverAllowed,
            'passed' => $this->passed(),
            'by_source' => $this->bySource(),
            'by_category' => $this->byCategory(),
            'failing' => $this->failing()->map(fn (CaseResult $result) => $result->toArray())->all(),
        ];
    }
}
