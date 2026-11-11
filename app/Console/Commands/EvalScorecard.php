<?php

namespace App\Console\Commands;

use App\Evals\MlflowReporter;
use App\Evals\Scorecard\Scorecard;
use Illuminate\Console\Command;
use Throwable;

class EvalScorecard extends Command
{
    protected $signature = 'eval:scorecard
        {feature : A feature with a scorecard in config/ai.php, for example support.reply}
        {--days=1 : The window}
        {--by= : variant: one column per variant (control, treatment, and any shadow candidate)}
        {--no-mlflow : Do not write the numbers to MLflow}';

    protected $description = 'A feature\'s scorecard by date and variant, floors marked, written to MLflow (Chapter 30, Topic 1)';

    public function handle(Scorecard $scorecard): int
    {
        $feature = (string) $this->argument('feature');

        if ((config('ai.scorecards')[$feature] ?? null) === null) {
            $this->error("There is no scorecard for {$feature} in config/ai.php (ai.scorecards).");

            return self::FAILURE;
        }

        $report = $scorecard->compute($feature, (int) $this->option('days'), $this->option('by') === 'variant');

        $this->print($report);

        if (! $this->option('no-mlflow')) {
            $this->mlflow($report);
        }

        return $report['stop']
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function print(array $report): void
    {
        $this->line("{$report['feature']} · {$report['to']}".($this->option('by') === 'variant' ? ' · by variant' : ''));

        $width = max(array_map(fn (array $column) => strlen($column['label']), $report['columns'])) + 2;

        $header = str_pad('metric', 30);

        foreach ($report['columns'] as $column) {
            $header .= str_pad($column['label'], max($width, 14) + 10);
        }

        $this->line($header.'floor');

        foreach ($report['rows'] as $row) {
            $line = str_pad($row['metric'], 30);

            foreach ($report['columns'] as $column) {
                $cell = $this->cell($row, $column);

                if (isset($row['flags'][$column['key']])) {
                    $cell .= '  ← '.$row['flags'][$column['key']];
                }

                $line .= str_pad($cell, max($width, 14) + 10);
            }

            $this->line($line.$this->format($row['metric'], $row['floor']));
        }

        $feedback = $report['feedback'];

        if ($report['feature'] === 'support.reply') {
            $share = $feedback['conversations'] > 0
                ? $feedback['rated'] / $feedback['conversations'] * 100
                : 0;

            $this->line(sprintf('feedback: %d of %d conversations rated (%.1f%%) · thumbs-down %d', $feedback['rated'], $feedback['conversations'], $share, $feedback['down']));
        }
    }

    private function cell(array $row, array $column): string
    {
        $value = $row['values'][$column['key']];

        if ($value !== null) {
            return $this->format($row['metric'], $value);
        }

        return $column['shadow'] && $row['metric'] === 'resolved_without_human_7d'
            ? '— (shadow: not shown)'
            : '—';
    }

    private function format(string $metric, float|int $value): string
    {
        return match (true) {
            str_ends_with($metric, '_usd') => '$'.number_format((float) $value, 4),
            str_ends_with($metric, '_seconds') => number_format((float) $value, 1).' s',
            $metric === 'never_gate_failures' => (string) (int) $value,
            $metric === 'judge_faithfulness_sample' => number_format((float) $value, 2),
            default => number_format((float) $value * 100, 1).'%',
        };
    }

    private function mlflow(array $report): void
    {
        $metrics = [];

        foreach ($report['rows'] as $row) {
            foreach ($row['values'] as $key => $value) {
                if ($value !== null) {
                    $metrics[str_replace([':', ' '], '_', $key).'.'.$row['metric']] = $value;
                }
            }
        }

        if ($metrics === []) {
            $this->line('Nothing to write to MLflow yet: no answers in that window.');

            return;
        }

        try {
            $run = MlflowReporter::fromConfig()->log('ledgerly-scorecards', "{$report['feature']}-{$report['to']}", $metrics, ['feature' => $report['feature'], 'days' => $this->option('days')]);

            $this->line("Written to MLflow, experiment ledgerly-scorecards, run {$run}.");
        } catch (Throwable $e) {
            $this->warn('MLflow was not updated: '.$e->getMessage());
        }
    }
}
