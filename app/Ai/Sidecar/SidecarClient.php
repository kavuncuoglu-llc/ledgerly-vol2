<?php

namespace App\Ai\Sidecar;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// The one door to the Python sidecar. When it does not answer, the door closes for a short cool-down so a dead sidecar costs one timeout, not one per question.
final class SidecarClient
{
    private const DOWN_KEY = 'ledgerly.sidecar.down';

    public function __construct(
        private string $url,
        private float $connectTimeout = 1.0,
        private float $timeout = 5.0,
        private int $cooldownSeconds = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            url: (string) config('ai.sidecar.url'),
            connectTimeout: (float) config('ai.sidecar.connect_timeout'),
            timeout: (float) config('ai.sidecar.timeout'),
            cooldownSeconds: (int) config('ai.sidecar.cooldown_seconds'),
        );
    }

    public function isDown(): bool
    {
        return Cache::has(self::DOWN_KEY);
    }

    public function forgetOutage(): void
    {
        Cache::forget(self::DOWN_KEY);
    }

    public function health(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/health'));
    }

    public function json(string $path, array $body, ?float $timeout = null): array
    {
        return $this->send(fn (PendingRequest $http) => $http->timeout($timeout ?? $this->timeout)->post($path, $body));
    }

    public function upload(string $path, string $filename, string $contents, ?float $timeout = null): array
    {
        return $this->send(fn (PendingRequest $http) => $http->timeout($timeout ?? $this->timeout)->attach('file', $contents, $filename)->post($path));
    }

    private function send(callable $request): array
    {
        if ($this->isDown()) {
            throw new SidecarUnavailable('The sidecar did not answer a moment ago; not trying again until the cool-down ends.');
        }

        try {
            $response = $request(Http::baseUrl($this->url)->connectTimeout($this->connectTimeout)->timeout($this->timeout)->acceptJson());
        } catch (ConnectionException $e) {
            throw $this->markDown("The sidecar at {$this->url} is not reachable: ".$e->getMessage());
        }

        return $this->decode($response);
    }

    private function decode(Response $response): array
    {
        if ($response->status() === 422) {
            throw new SidecarRejected((string) $response->json('detail', 'The sidecar rejected the request.'));
        }

        if ($response->failed()) {
            throw $this->markDown("The sidecar answered {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw $this->markDown('The sidecar answered with something that is not JSON.');
        }

        return $body;
    }

    private function markDown(string $reason): SidecarUnavailable
    {
        Cache::put(self::DOWN_KEY, true, $this->cooldownSeconds);

        Log::warning('Sidecar unavailable', ['reason' => $reason, 'cooldown_seconds' => $this->cooldownSeconds]);

        return new SidecarUnavailable($reason);
    }
}
