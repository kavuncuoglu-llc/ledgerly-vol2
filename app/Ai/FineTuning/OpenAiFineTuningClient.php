<?php

namespace App\Ai\FineTuning;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Chapter 41, Topic 4: the driver for a provider whose fine-tuning API has OpenAI's shape (files, fine_tuning/jobs, events, cancel). Written from that shape and tested with faked HTTP only: this repository's author had no hosted account, so it has never been run against a live provider (see HANDOFF "Unverified"). Appendix F.8 says what to check per provider.
final class OpenAiFineTuningClient implements FineTuningClient
{
    private const STATES = [
        'validating_files' => 'queued',
        'queued' => 'queued',
        'running' => 'running',
        'succeeded' => 'succeeded',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
    ];

    public function __construct(private string $baseUrl, private string $apiKey, private int $timeout = 60) {}

    public function uploadTrainingFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("No training file at {$path}.");
        }

        $response = $this->http()
            ->attach('file', (string) file_get_contents($path), basename($path))
            ->post('/files', ['purpose' => 'fine-tune']);

        return $this->body($response, 'upload')['id'];
    }

    public function createJob(FineTuneSpec $spec, string $fileId, ?string $validationFileId): string
    {
        $hyperparameters = array_filter([
            'n_epochs' => $spec->epochs,
            'batch_size' => $spec->batchSize,
            'learning_rate_multiplier' => $spec->learningRateMultiplier,
        ], fn ($value) => $value !== null);

        $body = array_filter([
            'model' => $spec->baseModel,
            'training_file' => $fileId,
            'validation_file' => $validationFileId,
            'method' => ['type' => $spec->method, $spec->method => ['hyperparameters' => $hyperparameters]],
            'seed' => $spec->seed,
            'suffix' => $spec->suffix,
        ], fn ($value) => $value !== null);

        return $this->body($this->http()->post('/fine_tuning/jobs', $body), 'create job')['id'];
    }

    public function status(string $jobId): FineTuneStatus
    {
        $job = $this->body($this->http()->get("/fine_tuning/jobs/{$jobId}"), 'status');

        return new FineTuneStatus(
            state: self::STATES[$job['status'] ?? ''] ?? 'running',
            trainedTokens: isset($job['trained_tokens']) ? (int) $job['trained_tokens'] : null,
            modelId: $job['fine_tuned_model'] ?? null,
            error: $job['error']['message'] ?? null,
        );
    }

    public function events(string $jobId, ?string $after = null): array
    {
        $query = array_filter(['after' => $after, 'limit' => 100]);

        $page = $this->body($this->http()->get("/fine_tuning/jobs/{$jobId}/events", $query), 'events');

        $events = array_map(fn (array $event) => [
            'id' => (string) $event['id'],
            'level' => (string) ($event['level'] ?? 'info'),
            'message' => (string) ($event['message'] ?? ''),
            'metrics' => $this->metrics($event['data'] ?? []),
            'at' => isset($event['created_at']) ? date('c', (int) $event['created_at']) : null,
        ], $page['data'] ?? []);

        usort($events, fn (array $a, array $b) => strcmp((string) $a['at'], (string) $b['at']));            // providers list newest first

        return $events;
    }

    public function cancel(string $jobId): void
    {
        $this->body($this->http()->post("/fine_tuning/jobs/{$jobId}/cancel"), 'cancel');
    }

    public function deleteFile(string $fileId): void
    {
        $response = $this->http()->delete("/files/{$fileId}");

        if ($response->status() === 404) {
            return;                                                    // already gone: deleting twice is fine
        }

        $this->body($response, 'delete file');
    }

    private function metrics(array $data): array
    {
        return array_filter([
            'step' => isset($data['step']) ? (int) $data['step'] : null,
            'train_loss' => isset($data['train_loss']) ? (float) $data['train_loss'] : null,
            'valid_loss' => isset($data['valid_loss']) ? (float) $data['valid_loss'] : null,
        ], fn ($value) => $value !== null);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))->withToken($this->apiKey)->acceptJson()->timeout($this->timeout);
    }

    private function body($response, string $what): array
    {
        if ($response->failed()) {
            throw new RuntimeException("The provider answered {$response->status()} for {$what}: ".mb_substr((string) $response->body(), 0, 200));
        }

        return $response->json() ?? [];
    }
}
