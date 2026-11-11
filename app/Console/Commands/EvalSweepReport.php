<?php

namespace App\Console\Commands;

use App\Ai\Training\Dataset;
use App\Evals\Sweep\BestSettings;
use App\Evals\Sweep\SweepReport;
use App\Evals\Sweep\SweepRun;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class EvalSweepReport extends Command
{
    protected $signature = 'eval:sweep-report
        {experiment : mlflow:<experiment>, such as mlflow:billing-sweep}
        {--set=golden:billing : The golden slice the runs were scored on (recorded in the report)}
        {--then=tripwire,injection : Checks the winner must pass: tripwire, injection}
        {--base-injection= : The base model\'s injection model-level success rate (0.14), the bar --then=injection holds a run to}
        {--manifest= : The sweep.json scripts/sweep.py wrote, to know each run\'s settings and its repeats}
        {--write-best= : A directory with billing-peft.yaml and billing-mlx.yaml to write the winning settings into}
        {--rows= : Training rows for the iterations count in billing-mlx.yaml (default: the dataset\'s train.jsonl)}
        {--dataset=billing-v1 : The dataset the production config trains on}';

    protected $description = 'Rank a sweep\'s MLflow runs on the golden set, check the winner on the tripwire and the injection suite, and write its settings into the production configs (Chapter 43)';

    public function handle(SweepReport $report): int
    {
        $experiment = (string) $this->argument('experiment');

        if (! str_starts_with($experiment, 'mlflow:')) {
            $this->error('The experiment is mlflow:<name>.');

            return self::FAILURE;
        }

        try {
            $runs = $report->runs(substr($experiment, 7), $this->manifest());

            $then = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('then')))));

            $result = $report->rank($runs, $then, (float) config('ai.compare.max_drop', 2.0), $this->option('base-injection') === null ? null : (float) $this->option('base-injection'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->print($result, count($runs));

        if ($result['selected'] === null) {
            $this->error('No run passed the checks: nothing to write.');

            return self::FAILURE;
        }

        if ($this->option('write-best') !== null) {
            return $this->writeBest($result['selected']);
        }

        return self::SUCCESS;
    }

    /** @return array<string, array> */
    private function manifest(): array
    {
        $path = $this->option('manifest');

        if ($path === null) {
            return [];
        }

        $absolute = str_starts_with((string) $path, '/')
            ? (string) $path
            : base_path((string) $path);

        if (! is_file($absolute)) {
            throw new InvalidArgumentException("No sweep manifest at {$absolute}: scripts/sweep.py writes sweep.json beside the configs.");
        }

        $runs = json_decode((string) file_get_contents($absolute), true)['runs'] ?? [];

        return array_combine(array_column($runs, 'name'), $runs);
    }

    private function print(array $result, int $count): void
    {
        $this->line("{$this->argument('experiment')} · {$count} runs · ranked by golden pass rate on {$this->option('set')}");

        $rows = array_map(fn (SweepRun $run) => [
            $run->params['learning_rate'] ?? '?',
            $run->params['lora_r'] ?? '?',
            $run->params['num_epochs'] ?? '?',
            $run->seed ?? '—',
            $this->fixed($run->validationLoss(), 2),
            $this->percent($run->metric('golden_pass_rate'), 1),
            $this->percent($run->metric('required_facts_rate'), 1),
            $run->tripwireDrop() === null ? '—' : sprintf('%+.1f', -$run->tripwireDrop()),
            $this->percent($run->injection(), 0),
            $this->mark($run, $result),
        ], $result['ranked']);

        $this->table(['lr', 'r', 'epochs', 'seed', 'val loss', 'golden pass', 'required facts', 'tripwire', 'injection', ''], $rows);

        foreach ($result['unscored'] as $run) {
            $this->warn("  {$run->name}: not scored (no golden_pass_rate on the run): php artisan eval:compare --roles=<its role> --log-to={$run->id}");
        }

        if ($result['seed_spread'] !== null) {
            $this->line("center point repeated: seed spread ±{$result['seed_spread']} points");
        }
    }

    private function mark(SweepRun $run, array $result): string
    {
        if ($run === $result['selected']) {
            return '← selected';
        }

        if ($run->rejected !== null) {
            return $run === $result['lowest_loss']
                ? "← lowest loss, rejected ({$run->rejected})"
                : "rejected ({$run->rejected})";
        }

        return '';
    }

    private function writeBest(SweepRun $best): int
    {
        $settings = ['learning_rate' => $best->params['learning_rate'] ?? null, 'lora_r' => $best->params['lora_r'] ?? null, 'num_epochs' => $best->params['num_epochs'] ?? null];

        if (in_array(null, $settings, true)) {
            $this->error("The selected run {$best->name} has no learning_rate, lora_r and num_epochs: pass --manifest.");

            return self::FAILURE;
        }

        try {
            $changed = (new BestSettings)->write((string) $this->option('write-best'), $settings, $this->rows());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($changed as $file => $lines) {
            $this->line("  {$file}: ".($lines === [] ? 'unchanged' : implode('; ', $lines)));
        }

        $this->line('  review it with git diff, and commit it as its own change.');

        return self::SUCCESS;
    }

    private function rows(): int
    {
        if ($this->option('rows') !== null) {
            return (int) $this->option('rows');
        }

        $dataset = Dataset::named((string) $this->option('dataset'));

        return $dataset->exists('train.jsonl')
            ? count($dataset->read('train.jsonl'))
            : 0;
    }

    private function percent(?float $value, int $decimals): string
    {
        return $value === null
            ? '—'
            : number_format($value * 100, $decimals).'%';
    }

    private function fixed(?float $value, int $decimals): string
    {
        return $value === null
            ? '—'
            : number_format($value, $decimals);
    }
}
