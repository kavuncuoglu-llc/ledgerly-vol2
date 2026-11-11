<?php

namespace App\Ai\Local;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

// Ollama's own /api/ endpoints, for the things the OpenAI-compatible /v1/ shape cannot show (timings, templates, raw prompts)
final class OllamaNative
{
    public function __construct(
        private string $baseUrl,
        private int $connectTimeout = 2,
        private int $timeout = 120,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: config('ai.base_url'),
            connectTimeout: (int) config('ai.connect_timeout'),
            timeout: (int) config('ai.timeout'),
        );
    }

    public function rootUrl(): string
    {
        return preg_replace('#/v1/?$#', '', rtrim($this->baseUrl, '/'));
    }

    public function chat(string $model, array $messages, array $options = []): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        if ($options !== []) {
            $body['options'] = $options;
        }

        return $this->http()->post('/api/chat', $body)->throw()->json();
    }

    public function generate(string $model, string $prompt, bool $raw = false, array $options = []): array
    {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'raw' => $raw,
        ];

        if ($options !== []) {
            $body['options'] = $options;
        }

        return $this->http()->post('/api/generate', $body)->throw()->json();
    }

    public function show(string $model): array
    {
        return $this->http()->post('/api/show', ['model' => $model, 'name' => $model])->throw()->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->rootUrl())
            ->acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }
}
