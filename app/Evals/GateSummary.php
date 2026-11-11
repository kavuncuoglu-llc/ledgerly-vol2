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
    // Chapter 35, Topic 4: a commit that triples the input tokens per answer fails review even when quality holds. Per answer, p50 and p95, from what the answers cost to ask.
    public function inputTokenPercentile(float $percentile): int
    {
        $tokens = $this->results
            ->map(fn (CaseResult $result) => $result->answer?->inputTokens ?? 0)
            ->filter(fn (int $count) => $count > 0)
            ->sort()
            ->values();

        if ($tokens->isEmpty()) {
            return 0;
        }

        return (int) $tokens[(int) max(0, ceil($percentile * $tokens->count()) - 1)];
    }

    public function inputTokensOk(): bool
    {
        return $this->inputTokenPercentile(0.95) <= (int) config('ai.context.call_budget', 6_000);
    }

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
            'input_tokens_p50' => $this->inputTokenPercentile(0.5),
            'input_tokens_p95' => $this->inputTokenPercentile(0.95),
            'by_source' => $this->bySource(),
            'by_category' => $this->byCategory(),
            'failing' => $this->failing()->map(fn (CaseResult $result) => $result->toArray())->all(),
        ];
    }
}
