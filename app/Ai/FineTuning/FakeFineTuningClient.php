<?php

namespace App\Ai\FineTuning;

use Illuminate\Support\Facades\File;
use RuntimeException;

// Chapter 41, Topic 8: the same six calls with nothing behind them. A job waits `seconds`, then reports a model ID; the chat route in FakeFineTunedChatController answers that ID with scripted billing replies. State lives in files, so a scheduler running in another process sees the same job.
final class FakeFineTuningClient implements FineTuningClient
{
    public function __construct(private string $directory, private int $seconds = 10) {}

    public function uploadTrainingFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("No training file at {$path}.");
        }

        $id = 'file-fake-'.substr(hash('sha256', $path.microtime()), 0, 10);

        $this->write($id, ['kind' => 'file', 'rows' => count(file($path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES)), 'deleted' => false]);

        return $id;
    }

    public function createJob(FineTuneSpec $spec, string $fileId, ?string $validationFileId): string
    {
        $file = $this->read($fileId);

        if (($file['deleted'] ?? true) === true) {
            throw new RuntimeException("The fake provider has no file {$fileId}.");
        }

        $id = 'ftjob-fake-'.substr(hash('sha256', $fileId.microtime()), 0, 10);

        $epochs = $spec->epochs ?? 3;

        $this->write($id, ['kind' => 'job', 'started' => time(), 'rows' => $file['rows'], 'epochs' => $epochs, 'base' => $spec->baseModel, 'cancelled' => false]);

        return $id;
    }

    public function status(string $jobId): FineTuneStatus
    {
        $job = $this->read($jobId);

        if ($job['cancelled']) {
            return new FineTuneStatus('cancelled');
        }

        $elapsed = time() - $job['started'];

        if ($elapsed < $this->seconds / 3) {
            return new FineTuneStatus('queued');
        }

        if ($elapsed < $this->seconds) {
            return new FineTuneStatus('running');
        }

        return new FineTuneStatus('succeeded', trainedTokens: $job['rows'] * $job['epochs'] * 150, modelId: 'ft:fake-billing:'.substr(hash('sha256', $jobId), 0, 8));
    }

    public function events(string $jobId, ?string $after = null): array
    {
        $job = $this->read($jobId);

        $steps = $this->steps($job, time() - $job['started']);

        $events = [];

        foreach ($steps as $step) {
            $id = "{$jobId}-step-{$step['step']}";

            if ($after !== null && $step['step'] <= $this->stepOf($after)) {
                continue;
            }

            $events[] = ['id' => $id, 'level' => 'info', 'message' => "Step {$step['step']}/{$step['of']}: training loss={$step['train_loss']}", 'metrics' => $step, 'at' => date('c')];
        }

        return $events;
    }

    public function cancel(string $jobId): void
    {
        $job = $this->read($jobId);

        $job['cancelled'] = true;

        $this->write($jobId, $job);
    }

    public function deleteFile(string $fileId): void
    {
        $file = $this->read($fileId);

        $file['deleted'] = true;

        $this->write($fileId, $file);
    }

    public function fileDeleted(string $fileId): bool
    {
        return ($this->read($fileId)['deleted'] ?? false) === true;
    }

    private function steps(array $job, int $elapsed): array
    {
        $of = max(1, (int) ceil($job['rows'] * $job['epochs'] / 8));

        $done = $elapsed >= $this->seconds
            ? $of
            : max(1, (int) floor($of * $elapsed / max(1, $this->seconds)));

        $steps = [];

        for ($step = 1; $step <= $done; $step++) {
            $steps[] = ['step' => $step, 'of' => $of, 'train_loss' => round(1.1 / (1 + $step / 20) - 0.3, 3), 'valid_loss' => round(1.2 / (1 + $step / 20) - 0.3, 3)];
        }

        return $steps;
    }

    private function stepOf(string $eventId): int
    {
        return (int) substr($eventId, (int) strrpos($eventId, '-') + 1);
    }

    private function read(string $id): array
    {
        $path = "{$this->directory}/{$id}.json";

        if (! is_file($path)) {
            throw new RuntimeException("The fake provider has no {$id}.");
        }

        return json_decode((string) file_get_contents($path), true);
    }

    private function write(string $id, array $data): void
    {
        File::ensureDirectoryExists($this->directory);

        file_put_contents("{$this->directory}/{$id}.json", json_encode($data));
    }
}
