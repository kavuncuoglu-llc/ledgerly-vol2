<?php

namespace App\Ai\Doctor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 25, Topic 5: Triton's checks, read through the authenticating proxy of ops/triton/proxy (the same address and key as the provider). Triton's own readiness endpoints say which of the repository's models loaded;
// its metrics say whether dynamic batching is doing anything (requests divided by executions, Figure 25-5).
final class TritonChecks implements ServerChecks
{
    public const REQUIRED = ['chat'];

    public const OPTIONAL = ['embedder', 'reranker', 'support_pipeline'];

    private ?string $metrics = null;

    public function __construct(
        private array $provider,
        private float $warmSeconds = 3.0,
    ) {}

    public function title(): string
    {
        return 'Triton at '.$this->root();
    }

    public function run(): array
    {
        $ready = $this->serverReady();

        if ($ready->status === Check::FAIL) {
            return [$ready];
        }

        return [
            $ready,
            ...$this->models(),
            $this->auth(),
            $this->modelControl(),
            $this->metricsCheck(),
            $this->roundTrip(),
            $this->licenses(),
        ];
    }

    public function health(): array
    {
        $ready = $this->serverReady();

        if ($ready->status === Check::FAIL) {
            return [Check::fail('reachable: no · loaded: unknown · warm: unknown · nothing answered at '.$this->root())];
        }

        $loaded = $this->modelReady('chat')
            ? 'yes (chat model ready)'
            : 'no (the chat model is not ready)';

        if (! $this->modelReady('chat')) {
            return [Check::fail("reachable: yes · loaded: {$loaded} · warm: unknown")];
        }

        $started = hrtime(true);

        try {
            $this->http()->post('/v1/chat/completions', $this->oneToken())->throw();
        } catch (ConnectionException|RequestException $e) {
            return [Check::fail("reachable: yes · loaded: {$loaded} · warm: no · a one-token completion failed: ".$this->reason($e))];
        }

        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        $warm = $seconds <= $this->warmSeconds
            ? sprintf('yes (completion %.2f s)', $seconds)
            : sprintf('no (first completion %.1f s) → run ai:warm', $seconds);

        return [Check::ok("reachable: yes · loaded: {$loaded} · warm: {$warm}")];
    }

    private function serverReady(): Check
    {
        try {
            $response = $this->http()->get('/v2/health/ready');
        } catch (ConnectionException) {
            return Check::fail("not reachable: nothing answered at {$this->root()}. Start it (`--profile triton` in Compose, on a host with an NVIDIA GPU) and check TRITON_URL in .env.");
        }

        if ($response->status() === 401) {
            return Check::fail('401 from the proxy: TRITON_API_KEY in .env is not the key the proxy was started with.');
        }

        if (! $response->successful()) {
            return Check::fail('/v2/health/ready answered '.$response->status().': Triton is up but not ready (still loading, or a model failed to load). docker compose logs triton says which.');
        }

        return Check::ok('/v2/health/ready ok');
    }

    /** @return list<Check> */
    private function models(): array
    {
        $checks = [];

        foreach (self::REQUIRED as $model) {
            $checks[] = $this->modelReady($model)
                ? Check::ok("model {$model} ready")
                : Check::fail("model {$model} is not ready: this is the language model the provider sends to. ops/triton/models/{$model}/1/model.json names it; docker compose logs triton says why it did not load.");
        }

        foreach (self::OPTIONAL as $model) {
            $checks[] = $this->modelReady($model)
                ? Check::ok("model {$model} ready")
                : Check::warn("model {$model} is not ready (not needed for chat): the ONNX files come from ops/triton/export_onnx.py");
        }

        return $checks;
    }

    private function modelReady(string $model): bool
    {
        try {
            return $this->http()->get("/v2/models/{$model}/ready")->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    // Triton has no authentication; the proxy is the whole of it. A request without the key must be refused, and the model-control API must not be reachable even with it.
    private function auth(): Check
    {
        if (blank($this->provider['api_key'] ?? null)) {
            return Check::warn('TRITON_API_KEY is empty in .env: requests go out with no key. Set one (and the same one on the proxy) so only the app can use Triton.');
        }

        try {
            $status = $this->http(withKey: false)->get('/v2/health/ready')->status();
        } catch (ConnectionException) {
            return Check::warn('could not test whether the proxy refuses requests without a key');
        }

        return in_array($status, [401, 403], true)
            ? Check::ok('auth required: a request without the API key is refused')
            : Check::warn("a request with no API key got {$status}, not 401: there is no authenticating proxy in front of Triton. Anyone who can reach it can use it.");
    }

    private function modelControl(): Check
    {
        try {
            $status = $this->http()->post('/v2/repository/index')->status();
        } catch (ConnectionException) {
            return Check::warn('could not test whether model control is refused');
        }

        return in_array($status, [401, 403, 404], true)
            ? Check::ok('model control (load and unload) is not reachable through this address')
            : Check::warn("POST /v2/repository/index answered {$status}: the model-control API is reachable. Anyone with the key could load or unload models; put the proxy in front (ops/triton/proxy).");
    }

    private function metricsCheck(): Check
    {
        $body = $this->metricsBody();

        if ($body === '' || ! str_contains($body, 'nv_inference_')) {
            return Check::warn('/metrics is not available or has no nv_inference series; Prometheus (the monitoring profile) will have nothing to scrape');
        }

        $batching = [];

        foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $model) {
            $requests = $this->counter('nv_inference_request_success', $model);
            $executions = $this->counter('nv_inference_exec_count', $model);

            if ($requests > 0 && $executions > 0) {
                $batching[] = sprintf('%s %.1f requests per execution', $model, $requests / $executions);
            }
        }

        return Check::ok('/metrics reachable'.($batching === [] ? '' : ' · '.implode(' · ', $batching)));
    }

    private function counter(string $name, string $model): float
    {
        if (preg_match('/^'.preg_quote($name, '/').'\{[^}]*model="'.preg_quote($model, '/').'"[^}]*\}\s+([0-9.eE+-]+)/m', $this->metricsBody(), $match) !== 1) {
            return 0.0;
        }

        return (float) $match[1];
    }

    private function roundTrip(): Check
    {
        $started = hrtime(true);

        try {
            $this->http()->post('/v1/chat/completions', $this->oneToken())->throw();
        } catch (ConnectionException|RequestException $e) {
            return Check::fail('/v1/chat/completions round trip failed: '.$this->reason($e));
        }

        return Check::ok(sprintf('/v1/chat/completions round-trip %.1f s', (hrtime(true) - $started) / 1_000_000_000));
    }

    private function licenses(): Check
    {
        $missing = array_keys(array_filter(config('ai.providers'), fn ($provider) => is_array($provider) && ($provider['driver'] ?? '') !== 'fake' && blank($provider['license'] ?? null)));

        return $missing === []
            ? Check::ok('license recorded for all providers')
            : Check::warn('license not recorded for '.implode(', ', $missing).' (Chapter 20: config/ai.php)');
    }

    private function metricsBody(): string
    {
        if ($this->metrics !== null) {
            return $this->metrics;
        }

        try {
            $response = $this->http()->get('/metrics');
        } catch (ConnectionException) {
            return $this->metrics = '';
        }

        return $this->metrics = $response->successful()
            ? $response->body()
            : '';
    }

    private function oneToken(): array
    {
        return [
            'model' => $this->provider['model'] ?? 'chat',
            'messages' => [['role' => 'user', 'content' => 'Reply with one word: ready.']],
            'max_tokens' => 1,
            'temperature' => 0,
            'stream' => false,
        ];
    }

    private function reason(Throwable $e): string
    {
        return trim(strtok($e->getMessage(), "\n") ?: $e::class);
    }

    private function root(): string
    {
        return preg_replace('#/v1/?$#', '', rtrim((string) $this->provider['base_url'], '/'));
    }

    private function http(bool $withKey = true): PendingRequest
    {
        $request = Http::baseUrl($this->root())
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout((int) ($this->provider['timeout'] ?? 60));

        return $withKey
            ? $request->withToken((string) ($this->provider['api_key'] ?? ''))
            : $request;
    }
}
