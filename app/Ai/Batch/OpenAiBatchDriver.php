<?php

namespace App\Ai\Batch;

use App\Models\AiBatch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Chapter 37, Topic 7: a hosted provider's batch API (the OpenAI shape: upload a JSONL file, create a batch against /v1/chat/completions with a 24 hour window, poll it, download the output file). Providers' batch APIs differ in the details;
// this is the shape most OpenAI-compatible providers copy. It was not run against a real provider here (none was available), only against a faked HTTP server in the tests.
final class OpenAiBatchDriver implements BatchDriver
{
    public function submit(AiBatch $batch): string
    {
        $file = $this->http($batch)->attach('file', (string) file_get_contents((string) $batch->input_path), basename((string) $batch->input_path))->post('/files', ['purpose' => 'batch'])->throw()->json('id');

        return (string) $this->http($batch)->post('/batches', ['input_file_id' => $file, 'endpoint' => '/v1/chat/completions', 'completion_window' => '24h'])->throw()->json('id');
    }

    public function status(AiBatch $batch): string
    {
        return match ($this->http($batch)->get('/batches/'.$batch->provider_batch_id)->throw()->json('status')) {
            'completed' => 'completed',
            'failed', 'expired', 'cancelled' => 'failed',
            default => 'processing',
        };
    }

    public function results(AiBatch $batch): iterable
    {
        $batchBody = $this->http($batch)->get('/batches/'.$batch->provider_batch_id)->throw()->json();

        foreach (['output_file_id', 'error_file_id'] as $field) {
            if (blank($batchBody[$field] ?? null)) {
                continue;
            }

            $lines = preg_split('/\R/', trim((string) $this->http($batch)->get('/files/'.$batchBody[$field].'/content')->throw()->body())) ?: [];

            foreach ($lines as $line) {
                $row = json_decode($line, true);

                if (! is_array($row) || ! isset($row['custom_id'])) {
                    continue;
                }

                $body = $row['response']['body'] ?? null;

                if (($row['response']['status_code'] ?? 0) !== 200 || ! is_array($body)) {
                    yield ['custom_id' => $row['custom_id'], 'error' => (string) ($row['error']['message'] ?? $body['error']['message'] ?? 'the provider could not process this request')];

                    continue;
                }

                yield ['custom_id' => $row['custom_id'], 'response' => ['content' => $body['choices'][0]['message']['content'] ?? '', 'usage' => $body['usage'] ?? [], 'model' => $body['model'] ?? '']];
            }
        }
    }

    private function http(AiBatch $batch): PendingRequest
    {
        $config = config("ai.providers.{$batch->provider}");

        if (! is_array($config) || blank($config['base_url'] ?? null)) {
            throw new RuntimeException("The provider [{$batch->provider}] is not set up for batches.");
        }

        return Http::baseUrl(rtrim((string) $config['base_url'], '/'))->withToken((string) ($config['api_key'] ?? ''))->acceptJson()->timeout(60);
    }
}
