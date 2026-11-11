<?php

namespace App\Console\Commands;

use App\Ai\FineTuning\FineTunePoller;
use App\Models\FineTuneJob;
use Illuminate\Console\Command;

class AiFtPoll extends Command
{
    protected $signature = 'ai:ft:poll';

    protected $description = 'Ask each running hosted fine-tune for its status and events; scheduled every five minutes (Chapter 41)';

    public function handle(FineTunePoller $poller): int
    {
        if (FineTuneJob::query()->whereIn('status', FineTuneJob::ACTIVE)->doesntExist()) {
            $this->line('No fine-tuning job is running.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($poller->poll() as $row) {
            $job = $row['job'];

            if ($row['error'] !== null) {
                $failed++;

                $this->error("  {$job->provider_job_id}: {$row['error']}");

                continue;
            }

            $this->line($this->describe($job));
        }

        return $failed === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function describe(FineTuneJob $job): string
    {
        $last = $job->events()->whereNotNull('metrics')->latest('id')->first();

        $loss = $last === null
            ? ''
            : sprintf(' · step %s · train loss %s · validation loss %s  → MLflow run %s', $last->metrics['step'] ?? '?', $last->metrics['train_loss'] ?? '?', $last->metrics['valid_loss'] ?? '?', $job->mlflow_run_id ?? 'not logged');

        return match ($job->status) {
            'succeeded' => "  {$job->provider_job_id} succeeded · model {$job->model_id} · trained tokens ".number_format((int) $job->trained_tokens).' · ledger: training row written · uploaded files deleted',
            'failed', 'cancelled' => "  {$job->provider_job_id} {$job->status} · {$job->failure_reason} · uploaded files deleted",
            default => "  {$job->provider_job_id} {$job->status}{$loss}",
        };
    }
}
