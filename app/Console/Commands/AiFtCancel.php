<?php

namespace App\Console\Commands;

use App\Ai\FineTuning\FineTuningClients;
use App\Models\FineTuneJob;
use Illuminate\Console\Command;
use Throwable;

class AiFtCancel extends Command
{
    protected $signature = 'ai:ft:cancel {job : The fine_tune_jobs id}';

    protected $description = 'Cancel a queued or running hosted fine-tune and delete its uploaded files (Chapter 41)';

    public function handle(FineTuningClients $clients): int
    {
        $job = FineTuneJob::query()->find((int) $this->argument('job'));

        if ($job === null) {
            $this->error('No fine-tuning job #'.$this->argument('job').'.');

            return self::FAILURE;
        }

        if (! in_array($job->status, [...FineTuneJob::ACTIVE, 'uploaded'], true)) {
            $this->error("Job #{$job->id} is {$job->status}: only an uploaded, queued or running job can be cancelled.");

            return self::FAILURE;
        }

        try {
            $client = $clients->for($job->provider);

            if ($job->provider_job_id !== null) {
                $client->cancel($job->provider_job_id);
            }

            foreach ([$job->provider_file_id, $job->provider_validation_file_id] as $fileId) {
                if ($fileId !== null) {
                    $client->deleteFile($fileId);
                }
            }
        } catch (Throwable $e) {
            $this->error('The provider did not cancel it: '.$e->getMessage());

            return self::FAILURE;
        }

        $job->update(['failure_reason' => 'Cancelled by an operator.']);

        $job->transitionTo('cancelled');

        $this->line("  job #{$job->id} cancelled · uploaded files deleted at the provider");

        return self::SUCCESS;
    }
}
