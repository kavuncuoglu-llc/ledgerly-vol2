<?php

namespace App\Ai\Native;

use App\Ai\AiManager;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Provider-shaped by nature (it speaks the wire format directly), so it lives under app/Ai/Native. It sends one request to a real provider and saves the whole exchange, with the key redacted.
final class ExchangeRecorder
{
    public function __construct(private AiManager $ai) {}

    /** @return string the path written */
    public function record(string $provider, string $fixture, array $prompt, bool $stream = false): string
    {
        if (! $this->ai->configured($provider)) {
            throw new RuntimeException("The '{$provider}' provider is not set up, so there is nothing to record against.");
        }

        $config = $this->ai->config($provider);

        $body = array_filter([
            'model' => $prompt['model'] ?? $config['model'],
            'messages' => $prompt['messages'] ?? throw new RuntimeException('The prompt file needs a "messages" array.'),
            'tools' => $prompt['tools'] ?? null,
            'response_format' => $prompt['response_format'] ?? null,
            'stream' => $stream ?: null,
            'stream_options' => $stream ? ['include_usage' => true] : null,
        ] + ($prompt['options'] ?? []), fn ($value) => $value !== null);

        $response = Http::baseUrl((string) $config['base_url'])
            ->withToken((string) ($config['api_key'] ?? ''))
            ->acceptJson()
            ->timeout((int) ($config['timeout'] ?? 60))
            ->post('/chat/completions', $body);

        $exchange = [
            'recorded_at' => now()->toIso8601String(),
            'provider' => $provider,
            'driver' => $config['driver'],
            'request' => ['method' => 'POST', 'path' => '/chat/completions', 'body' => $body],       // the key travels in a header and is never stored
            'response' => [
                'status' => $response->status(),
                'headers' => array_filter(['Content-Type' => $response->header('Content-Type'), 'Retry-After' => $response->header('Retry-After')]),
                'body' => $stream ? $response->body() : ($response->json() ?? $response->body()),
            ],
        ];

        $path = base_path("storage/fixtures/{$config['driver']}/{$fixture}.json");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($exchange, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }
}
