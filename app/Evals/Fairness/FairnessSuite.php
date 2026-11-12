<?php

namespace App\Evals\Fairness;

use App\Evals\GoldenCase;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

// Chapter 51, Topic 3: every variant of every base case, several runs each, then a paired bootstrap per dimension and measure against a tolerance set in advance. A clean row says "no evidence of disparity at this sample size", never "fair".
final class FairnessSuite
{
    public function __construct(private VariantRunner $runner, private FairnessData $data, private FairnessStats $stats) {}

    /**
     * @param  Collection<int, GoldenCase>  $base
     * @param  list<string>  $dimensions
     * @param  Closure(int, int): void|null  $progress  runs done, runs planned
     * @return array<string, mixed>
     */
    public function run(Collection $base, array $dimensions, int $runs, ?float $tolerance = null, ?Closure $progress = null): array
    {
        $dimensions = $this->data->assertDimensions($dimensions);

        $tolerance ??= (float) config('fairness.tolerance');

        $plan = $base->map(fn (GoldenCase $case) => $this->data->variants($case, $dimensions))->all();

        $planned = array_sum(array_map('count', $plan)) * $runs;

        $cells = [];

        $done = 0;

        foreach ($plan as $variants) {
            foreach ($variants as $variant) {
                for ($run = 0; $run < $runs; $run++) {
                    $cells[$variant->baseId][$variant->dimension][$variant->level][] = Measures::of($this->runner->run($variant->case));

                    $done++;

                    if ($progress !== null) {
                        $progress($done, $planned);
                    }
                }
            }
        }

        return $this->report($cells, $base->count(), $dimensions, $runs, $planned, $tolerance);
    }

    /** @return array<string, mixed> */
    public function report(array $cells, int $baseCount, array $dimensions, int $runs, int $planned, float $tolerance): array
    {
        $rows = [];

        foreach ($dimensions as $dimension) {
            foreach ((array) config('fairness.measures') as $measure) {
                $row = $this->row($cells, $dimension, (string) $measure, $tolerance);

                if ($row === null) {
                    continue;
                }

                $primary = config("fairness.dimensions.{$dimension}.measure") === $measure;

                if ($primary || $row['state'] === 'flagged') {
                    $rows[] = $row + ['primary' => $primary];
                }
            }
        }

        return [
            'at' => CarbonImmutable::now()->toIso8601String(),
            'base_cases' => $baseCount,
            'dimensions' => $dimensions,
            'runs' => $runs,
            'variant_runs' => $planned,
            'tolerance' => $tolerance,
            'confidence' => (float) config('fairness.confidence'),
            'rows' => $rows,
            'flagged' => array_values(array_unique(array_column(array_filter($rows, fn (array $row) => $row['state'] === 'flagged'), 'dimension'))),
            'unreviewed' => array_values(array_filter($dimensions, fn (string $dimension) => ! $this->data->reviewed($dimension))),
        ];
    }

    private function row(array $cells, string $dimension, string $measure, float $tolerance): ?array
    {
        $perCase = [];

        foreach ($cells as $baseId => $byDimension) {
            $levels = [];

            foreach (['control' => $byDimension['control'] ?? [], ...($byDimension[$dimension] ?? [])] as $level => $runs) {
                $values = array_values(array_filter(array_column($runs, $measure), fn ($value) => $value !== null));

                if ($values !== []) {
                    $levels[$level === 'control' ? 'control' : $level] = array_sum($values) / count($values) * ($measure === 'tone' ? 1 : 100);
                }
            }

            if (count($levels) > 1) {
                $perCase[$baseId] = $levels;
            }
        }

        $gap = $this->stats->largestGap($perCase, (int) config('fairness.bootstrap'), (float) config('fairness.confidence'), (int) config('fairness.seed'));

        if ($gap === null) {
            return null;
        }

        [$state, $verdict] = $this->stats->verdict($gap, $tolerance, (int) config('fairness.min_base_cases'));

        return ['dimension' => $dimension, 'measure' => $measure] + $gap + ['state' => $state, 'verdict' => $verdict];
    }
}
