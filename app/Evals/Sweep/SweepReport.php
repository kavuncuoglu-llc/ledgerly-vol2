<?php

namespace App\Evals\Sweep;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

// Chapter 43, Topic 7: the runs of a sweep, read from the MLflow experiment, ranked on the golden set (not on loss) and then held to the tripwire and the injection suite. A run that wins on quality by forgetting more, or by refusing less, has not won. The runs' golden metrics are put there by `eval:compare --log-to=<run id>` after each adapter is served and measured; a run without them is listed as not scored, never guessed.
final class SweepReport
{
    public function __construct(private string $baseUrl) {}

    public static function fromConfig(): self
    {
        return new self(rtrim((string) config('services.mlflow.tracking_uri', 'http://localhost:5050'), '/'));
    }

    /** @param array<string, array{params: array, seed: ?int, center_repeat: bool}> $manifest runs by name, from sweep.json
     * @return list<SweepRun> */
    public function runs(string $experiment, array $manifest = []): array
    {
        $found = Http::acceptJson()->get($this->baseUrl.'/api/2.0/mlflow/experiments/get-by-name', ['experiment_name' => $experiment]);

        if ($found->failed()) {
            throw new RuntimeException("MLflow has no experiment [{$experiment}] at {$this->baseUrl}.");
        }

        $search = Http::acceptJson()->post($this->baseUrl.'/api/2.0/mlflow/runs/search', ['experiment_ids' => [(string) $found->json('experiment.experiment_id')], 'max_results' => 500]);

        if ($search->failed()) {
            throw new RuntimeException("MLflow answered {$search->status()} searching the runs of [{$experiment}].");
        }

        return array_map(fn (array $run) => $this->one($run, $manifest), $search->json('runs') ?? []);
    }

    /**
     * Rank on golden pass rate, then required facts, then validation loss, and reject what the checks reject.
     *
     * @param  list<SweepRun>  $runs
     * @param  list<string>  $then  tripwire, injection
     * @return array{ranked: list<SweepRun>, unscored: list<SweepRun>, selected: ?SweepRun, lowest_loss: ?SweepRun, seed_spread: ?float}
     */
    public function rank(array $runs, array $then, float $maxDrop, ?float $baseInjection, float $maxInjectionRise = 0.02): array
    {
        if (in_array('injection', $then, true) && $baseInjection === null) {
            throw new InvalidArgumentException('--then=injection needs --base-injection=<the base model\'s model-level success rate, such as 0.14>.');
        }

        $scored = array_values(array_filter($runs, fn (SweepRun $run) => $run->scored()));

        $unscored = array_values(array_filter($runs, fn (SweepRun $run) => ! $run->scored()));

        usort($scored, fn (SweepRun $a, SweepRun $b) => [$b->metric('golden_pass_rate'), $b->metric('required_facts_rate') ?? 0, $a->validationLoss() ?? INF] <=> [$a->metric('golden_pass_rate'), $a->metric('required_facts_rate') ?? 0, $b->validationLoss() ?? INF]);

        $selected = null;

        foreach ($scored as $run) {
            $run->rejected = $this->rejection($run, $then, $maxDrop, $baseInjection, $maxInjectionRise);

            if ($selected === null && $run->rejected === null) {
                $selected = $run;
            }
        }

        $withLoss = array_values(array_filter($scored, fn (SweepRun $run) => $run->validationLoss() !== null));

        usort($withLoss, fn (SweepRun $a, SweepRun $b) => $a->validationLoss() <=> $b->validationLoss());

        return ['ranked' => $scored, 'unscored' => $unscored, 'selected' => $selected, 'lowest_loss' => $withLoss[0] ?? null, 'seed_spread' => $this->seedSpread($scored)];
    }

    private function rejection(SweepRun $run, array $then, float $maxDrop, ?float $baseInjection, float $maxInjectionRise): ?string
    {
        $reasons = [];

        if (in_array('tripwire', $then, true)) {
            $drop = $run->tripwireDrop();

            if ($drop === null) {
                $reasons[] = 'no tripwire result';
            } elseif ($drop > $maxDrop) {
                $reasons[] = sprintf('tripwire %+.1f', -$drop);
            }
        }

        if (in_array('injection', $then, true)) {
            $rate = $run->injection();

            if ($rate === null) {
                $reasons[] = 'no injection result';
            } elseif ($rate > $baseInjection + $maxInjectionRise) {
                $reasons[] = sprintf('injection %d%%', round($rate * 100));
            }
        }

        return $reasons === []
            ? null
            : implode(', ', $reasons);
    }

    /** @param list<SweepRun> $scored */
    private function seedSpread(array $scored): ?float
    {
        $center = array_values(array_filter($scored, fn (SweepRun $run) => $run->centerRepeat));

        if ($center === []) {
            return null;
        }

        $twin = array_values(array_filter($scored, fn (SweepRun $run) => ! $run->centerRepeat && $run->params == $center[0]->params));

        $rates = array_map(fn (SweepRun $run) => $run->metric('golden_pass_rate') * 100, [...$center, ...$twin]);

        return count($rates) < 2
            ? null
            : round((max($rates) - min($rates)) / 2, 2);
    }

    private function one(array $run, array $manifest): SweepRun
    {
        $tags = [];

        foreach ($run['data']['tags'] ?? [] as $tag) {
            $tags[$tag['key']] = $tag['value'];
        }

        $name = (string) ($run['info']['run_name'] ?? $tags['mlflow.runName'] ?? $run['info']['run_id']);

        $params = [];

        foreach ($run['data']['params'] ?? [] as $param) {
            $params[$param['key']] = is_numeric($param['value']) ? $param['value'] + 0 : $param['value'];
        }

        $metrics = [];

        foreach ($run['data']['metrics'] ?? [] as $metric) {
            $metrics[$metric['key']] = (float) $metric['value'];
        }

        $known = $manifest[$name] ?? null;

        return new SweepRun((string) $run['info']['run_id'], $name, $known['params'] ?? $params, $metrics, (bool) ($known['center_repeat'] ?? false), $known['seed'] ?? null);
    }
}
