<?php

namespace App\Ai\Listeners;

use App\Ai\Budgets\Notifier;
use App\Ai\FineTuning\FineTuneEnded;
use App\Ai\FineTuning\FineTuneSucceeded;

// Chapter 41, Topic 4: the owning team hears when a job ends, whichever way.
final class NotifyFineTuneFinished
{
    public function __construct(private Notifier $notifier) {}

    public function handle(FineTuneSucceeded|FineTuneEnded $event): void
    {
        $job = $event->job;

        $subject = $event instanceof FineTuneSucceeded
            ? "Fine-tune #{$job->id} succeeded: {$job->model_id}"
            : "Fine-tune #{$job->id} {$job->status}";

        $body = $event instanceof FineTuneSucceeded
            ? "{$job->dataset} ({$job->dataset_hash}) on {$job->base_model}: {$job->trained_tokens} trained tokens. Register it with php artisan ai:ft:register {$job->id} --role=frontier-billing-ft."
            : "{$job->dataset} on {$job->base_model}: {$job->failure_reason}. The uploaded files were deleted at the provider.";

        $this->notifier->team((string) config('ai.fine_tuning.notify_team'), $subject, $body);
    }
}
