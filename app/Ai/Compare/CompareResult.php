<?php

namespace App\Ai\Compare;

// The samples of one comparison, folded into the Figure 20-8 table: per feature and provider, then quality by category.
final class CompareResult
{
    /** @param list<array> $samples */
    public function __construct(public readonly array $samples, public readonly array $providers) {}

    /** @return list<array{feature: string, provider: string, cases: int, errors: int, quality: ?float, in: int, out: int, p50_ms: int, p50_ttft_ms: ?int, usd_per_1k: float, quality_per_dollar: ?float}> */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->groups() as $feature => $byProvider) {
            foreach ($this->providers as $provider) {
                $rows[] = $this->row($feature, $provider, $byProvider[$provider] ?? []);
            }
        }

        return $rows;
    }

    /** @return array<string, array<string, array<string, float>>> feature => provider => category => quality */
    public function byCategory(): array
    {
        $table = [];

        foreach ($this->samples as $sample) {
            if (($sample['error'] ?? null) !== null || $sample['quality'] === null) {
                continue;
            }

            $table[$sample['case']->feature][$sample['provider']][$sample['case']->category][] = $sample['quality'];
        }

        return array_map(fn (array $providers) => array_map(fn (array $categories) => array_map(fn (array $scores) => round(array_sum($scores) / count($scores), 2), $categories), $providers), $table);
    }

    /** @return list<array{provider: string, case: string, error: string}> */
    public function errors(): array
    {
        return array_values(array_map(
            fn (array $sample) => ['provider' => $sample['provider'], 'case' => $sample['case']->id, 'error' => $sample['error']],
            array_filter($this->samples, fn (array $sample) => ($sample['error'] ?? null) !== null),
        ));
    }

    public function toArray(): array
    {
        return [
            'rows' => $this->rows(),
            'by_category' => $this->byCategory(),
            'errors' => $this->errors(),
            'cases' => count(array_unique(array_map(fn (array $sample) => $sample['case']->id, $this->samples))),
        ];
    }

    private function groups(): array
    {
        $groups = [];

        foreach ($this->samples as $sample) {
            $groups[$sample['case']->feature][$sample['provider']][] = $sample;
        }

        return $groups;
    }

    private function row(string $feature, string $provider, array $samples): array
    {
        $answered = array_values(array_filter($samples, fn (array $sample) => ($sample['error'] ?? null) === null));

        $scored = array_values(array_filter(array_column($answered, 'quality'), fn ($score) => $score !== null));

        $quality = $scored === []
            ? null
            : round($this->mean($scored), 2);

        $usdPer1k = $answered === []
            ? 0.0
            : $this->mean(array_column($answered, 'cost')) * 1000;

        $ttfts = array_filter(array_column($answered, 'ttft'), fn ($value) => $value !== null);

        return [
            'feature' => $feature,
            'provider' => $provider,
            'cases' => count($samples),
            'errors' => count($samples) - count($answered),
            'quality' => $quality,
            'in' => $answered === [] ? 0 : (int) round($this->mean(array_column($answered, 'in'))),
            'out' => $answered === [] ? 0 : (int) round($this->mean(array_column($answered, 'out'))),
            'p50_ms' => $answered === [] ? 0 : $this->median(array_column($answered, 'ms')),
            'p50_ttft_ms' => $ttfts === [] ? null : $this->median(array_values($ttfts)),
            'usd_per_1k' => round($usdPer1k, 2),
            'quality_per_dollar' => $quality === null || $usdPer1k < 0.005
                ? null
                : round($quality / $usdPer1k, 2),
        ];
    }

    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    private function median(array $values): int
    {
        sort($values);

        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (int) $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
