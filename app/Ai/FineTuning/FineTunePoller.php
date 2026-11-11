<?php

namespace App\Ai\FineTuning;

use App\Ai\Ledger\TrainingLedger;
use App\Evals\MlflowReporter;
use App\Models\FineTuneJob;
use Illuminate\Support\Facades\Log;
use Throwable;

// Chapter 41, Figure 41-4 (PollFineTunes): ask each running job's provider for status and new events, store the events, send the reported loss to MLflow under the job's run, move the job through its states and, when it ends, write the training row, delete the uploaded files and tell the owning team. One provider failing to answer is logged and skipped: the next poll is five minutes away.
final class FineTunePoller
{
    public function __construct(private FineTuningClients $clients, private MlflowReporter $mlflow, private TrainingLedger $ledger) {}

    /** @return list<array{job: FineTuneJob, from: string, to: string, error: ?string}> */
    public function poll(): array
    {
        $report = [];

        foreach (FineTuneJob::query()->whereIn('status', FineTuneJob::ACTIVE)->get() as $job) {
            $from = $job->status;

            try {
                $this->pollOne($job);

                $report[] = ['job' => $job->fresh(), 'from' => $from, 'to' => $job->fresh()->status, 'error' => null];
            } catch (Throwable $e) {
                Log::warning('ai.ft.poll_failed', ['job' => $job->id, 'error' => $e->getMessage()]);

                $report[] = ['job' => $job, 'from' => $from, 'to' => $from, 'error' => $e->getMessage()];
            }
        }

        return $report;
    }

    private function pollOne(FineTuneJob $job): void
    {
        $client = $this->clients->for($job->provider);

        $status = $client->status($job->provider_job_id);

        $job->recordEvents($client->events($job->provider_job_id, after: $job->last_event_id));

        $this->sendLossToMlflow($job);

        $job->transitionTo($status->state);

        if ($status->state === 'succeeded') {
            $job->update(['model_id' => $status->modelId, 'trained_tokens' => $status->trainedTokens]);

            $this->ledger->record($job->fresh());

            $this->deleteFiles($client, $job);

            $this->finishRun($job);

            event(new FineTuneSucceeded($job->fresh()));

            return;
        }

        if (in_array($status->state, ['failed', 'cancelled'], true)) {
            $job->update(['failure_reason' => $status->error ?? "The provider reported {$status->state}."]);

            $this->deleteFiles($client, $job);

            $this->finishRun($job, 'FAILED');

            event(new FineTuneEnded($job->fresh()));
        }
    }

    private function deleteFiles(FineTuningClient $client, FineTuneJob $job): void
    {
        foreach ([$job->provider_file_id, $job->provider_validation_file_id] as $fileId) {
            if ($fileId !== null) {
                $client->deleteFile($fileId);
            }
        }
    }

    private function sendLossToMlflow(FineTuneJob $job): void
    {
        $pending = $job->events()->where('mlflow_logged', false)->whereNotNull('metrics')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            return;
        }

        try {
            $runId = $job->mlflow_run_id ?? $this->mlflow->start(
                (string) config('ai.fine_tuning.mlflow_experiment'),
                "hosted-{$job->dataset}",
                ['provider' => $job->provider, 'base_model' => (string) $job->base_model, 'dataset' => $job->dataset, 'dataset_hash' => $job->dataset_hash, 'job' => (string) $job->provider_job_id],
            );

            $job->update(['mlflow_run_id' => $runId]);

            $metrics = [];

            foreach ($pending as $event) {
                foreach (['train_loss', 'valid_loss'] as $key) {
                    if (isset($event->metrics[$key])) {
                        $metrics[] = ['key' => $key, 'value' => $event->metrics[$key], 'step' => $event->metrics['step'] ?? 0];
                    }
                }
            }

            if ($metrics !== []) {
                $this->mlflow->metrics($runId, $metrics);
            }

            $job->events()->whereIn('id', $pending->pluck('id'))->update(['mlflow_logged' => true]);
        } catch (Throwable $e) {
            Log::warning('ai.ft.mlflow_unreachable', ['job' => $job->id, 'error' => $e->getMessage()]);            // the events stay unlogged and go with the next poll
        }
    }

    private function finishRun(FineTuneJob $job, string $status = 'FINISHED'): void
    {
        if ($job->mlflow_run_id === null) {
            return;
        }

        try {
            $this->mlflow->finish($job->mlflow_run_id, $status);
        } catch (Throwable $e) {
            Log::warning('ai.ft.mlflow_unreachable', ['job' => $job->id, 'error' => $e->getMessage()]);
        }
    }
}
