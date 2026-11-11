<?php

namespace App\Ai\FineTuning;

// Chapter 41, Figure 41-4: one interface, a driver per provider.
interface FineTuningClient
{
    public function uploadTrainingFile(string $path): string;                       // returns the provider's file ID

    public function createJob(FineTuneSpec $spec, string $fileId, ?string $validationFileId): string;

    public function status(string $jobId): FineTuneStatus;                          // state, trained tokens, model ID when done

    public function events(string $jobId, ?string $after = null): array;            // messages and reported metrics

    public function cancel(string $jobId): void;

    public function deleteFile(string $fileId): void;
}
