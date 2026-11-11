<?php

namespace App\Console\Commands;

use App\Ai\Prompts\SupportPrompt;
use App\Evals\MlflowReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class EvalReport extends Command
{
    protected $signature = 'eval:report
        {--from= : A summary written by eval:check or eval:gates (default: the newest in storage/eval)}
        {--to= : mlflow: log the numbers as a run in the ledgerly-evals experiment}
        {--notify= : A channel name for the summary; it is posted to EVAL_NOTIFY_WEBHOOK, or printed when none is set}
        {--run-name= : The MLflow run name (default: nightly-DATE)}';

    protected $description = 'Report a gate run to MLflow and to the team channel (Chapter 29)';

    public function handle(): int
    {
        try {
            $summary = $this->summary();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->text($summary));

        if ($this->option('to') === 'mlflow') {
            if (! $this->toMlflow($summary)) {
                return self::FAILURE;
            }
        }

        if ($this->option('notify')) {
            $this->notify((string) $this->option('notify'), $this->text($summary));
        }

        return self::SUCCESS;
    }

    private function summary(): array
    {
        $path = $this->option('from')
            ? base_path((string) $this->option('from'))
            : $this->newest();

        if (! File::exists($path)) {
            throw new RuntimeException('There is no summary to report. Run eval:check or eval:gates first.');
        }

        return json_decode(File::get($path), true) ?? throw new RuntimeException("{$path} is not JSON.");
    }

    private function newest(): string
    {
        return collect(File::glob(storage_path('eval/last-*.json')))
            ->sortByDesc(fn (string $path) => filemtime($path))
            ->first() ?? storage_path('eval/last-check.json');
    }

    private function text(array $summary): string
    {
        $line = sprintf(
            'Ledgerly golden set · %d cases · quality %.1f%% (needs %d%%) · never-gate failures %d (allowed %d) · %s',
            $summary['cases'],
            $summary['quality_pass_rate'] * 100,
            (int) round($summary['quality_threshold'] * 100),
            $summary['never_failures'],
            $summary['never_allowed'],
            $summary['passed'] ? 'PASS' : 'FAIL',
        );

        if (($summary['judge_pass_rate'] ?? null) !== null) {
            $line .= sprintf(' · judge %.1f%%', $summary['judge_pass_rate'] * 100);
        }

        foreach ($summary['by_source'] ?? [] as $source => $row) {
            $line .= sprintf(' · %s %.1f%%', $source, $row['quality_pass_rate'] * 100);
        }

        return $line;
    }

    private function toMlflow(array $summary): bool
    {
        $metrics = [
            'quality_pass_rate' => $summary['quality_pass_rate'],
            'never_gate_failures' => $summary['never_failures'],
            'cases' => $summary['cases'],
        ];

        if (($summary['judge_pass_rate'] ?? null) !== null) {
            $metrics['judge_pass_rate'] = $summary['judge_pass_rate'];
        }

        foreach ($summary['by_source'] ?? [] as $source => $row) {
            $metrics["quality_pass_rate_{$source}"] = $row['quality_pass_rate'];
        }

        $params = [
            'prompt_version' => SupportPrompt::VERSION,
            'model' => config('ai.providers.local.model', config('ai.model')),
            'quality_threshold' => $summary['quality_threshold'],
            'commit' => getenv('GITHUB_SHA') ?: 'local',
            'kind' => $summary['kind'] ?? 'gates',
        ];

        try {
            $run = MlflowReporter::fromConfig()->log('ledgerly-evals', $this->option('run-name') ?: 'nightly-'.now()->toDateString(), $metrics, $params, ['mlflow.runName' => $this->option('run-name') ?: 'nightly-'.now()->toDateString()]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }

        $this->info("Logged to MLflow, run {$run}, experiment ledgerly-evals.");

        return true;
    }

    private function notify(string $channel, string $text): void
    {
        $webhook = (string) config('ai.eval.notify_webhook');

        if ($webhook === '') {
            $this->line("[{$channel}] ".$text.'  (EVAL_NOTIFY_WEBHOOK is not set, so nothing was posted)');

            return;
        }

        $response = Http::timeout(10)->post($webhook, ['channel' => $channel, 'text' => $text]);

        $this->line($response->successful()
            ? "Posted to {$channel}."
            : "Posting to {$channel} failed with {$response->status()}.");
    }
}
