<?php

namespace App\Ai\FineTuning;

use App\Models\FineTuneJob;
use InvalidArgumentException;

// Chapter 41, Topic 4: submission is idempotent by dataset hash, base model and settings. A second submission of the same thing is refused unless asked for with `again`, because an accidental resubmit is an accidental bill. A failed or cancelled job does not count: it can be tried again.
final class FineTuneSubmitter
{
    public function __construct(private FineTuningClients $clients) {}

    public function submit(string $dataset, string $provider, FineTuneSpec $spec, bool $withValidation = true, bool $again = false): FineTuneJob
    {
        $job = $this->prepared($dataset, $provider);

        $fingerprint = $this->fingerprint($job, $spec);

        $existing = FineTuneJob::query()
            ->where('fingerprint', $fingerprint)
            ->whereNotIn('status', ['failed', 'cancelled', 'prepared'])
            ->first();

        if ($existing !== null && ! $again) {
            throw new DuplicateFineTune("Job #{$existing->id} ({$existing->status}) already trains this dataset hash, base and settings. Pass --again to pay for it a second time.");
        }

        $job = $this->fresh($job);

        $client = $this->clients->for($provider);

        $fileId = $client->uploadTrainingFile($job->file_path);

        $job->update(['provider_file_id' => $fileId, 'status' => 'uploaded']);

        $validationId = null;

        if ($withValidation && $job->validation_path !== null) {
            $validationId = $client->uploadTrainingFile($job->validation_path);

            $job->update(['provider_validation_file_id' => $validationId]);
        }

        $jobId = $client->createJob($spec, $fileId, $validationId);

        $job->update([
            'provider_job_id' => $jobId,
            'base_model' => $spec->baseModel,
            'method' => $spec->method,
            'settings' => $spec->settings(),
            'fingerprint' => $fingerprint,
            'status' => 'queued',
            'submitted_at' => now(),
        ]);

        return $job->fresh();
    }

    private function prepared(string $dataset, string $provider): FineTuneJob
    {
        $job = FineTuneJob::query()
            ->where('dataset', $dataset)
            ->where('provider', $provider)
            ->whereNotNull('file_path')
            ->latest('id')
            ->first();

        return $job ?? throw new InvalidArgumentException("No prepared file for {$dataset} on {$provider}: run php artisan ai:ft:prepare {$dataset} --provider={$provider} first.");
    }

    private function fresh(FineTuneJob $job): FineTuneJob
    {
        if ($job->status === 'prepared') {
            return $job;
        }

        return FineTuneJob::query()->create($job->only(['provider', 'driver', 'dataset', 'dataset_hash', 'sources', 'file_path', 'validation_path', 'row_count', 'tokens_per_epoch']) + ['status' => 'prepared']);
    }

    private function fingerprint(FineTuneJob $job, FineTuneSpec $spec): string
    {
        return hash('sha256', json_encode([$job->dataset_hash, $job->sources, $spec->baseModel, $spec->method, $spec->settings()]));
    }
}
