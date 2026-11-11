<?php

namespace App\Ai\Doctor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 24, Topic 8: vLLM's own checks. The startup capacity line is read from /metrics (the cache_config_info gauge carries the number of KV blocks and their size);
// what the API cannot say (the tool parser, whether the weights are FP8) is read from .env and the model name, and the line says so.
final class VllmChecks implements ServerChecks
{
    private ?string $metrics = null;

    private ?array $models = null;

    public function __construct(
        private array $provider,
        private int $queueProcesses,
        private int $budgetWindow,
        private array $settings,
        private float $warmSeconds = 3.0,
    ) {}

    public function title(): string
    {
        return 'vLLM at '.$this->root();
    }

    public function run(): array
    {
        $health = $this->healthCheck();

        if ($health->status === Check::FAIL) {
            return [$health];
        }

        return [
            $health,
            $this->maxModelLength(),
            ...$this->capacity(),
            $this->prefixCaching(),
            $this->toolParser(),
            $this->auth(),
            $this->metricsCheck(),
            $this->roundTrip(),
            $this->licenses(),
        ];
    }

    public function health(): array
    {
        $health = $this->healthCheck();

        if ($health->status === Check::FAIL) {
            return [Check::fail('reachable: no · loaded: unknown · warm: unknown · nothing answered at '.$this->root())];
        }

        $loaded = $this->kvTokens() > 0
            ? 'yes (KV cache reported)'
            : 'yes';

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

    private function healthCheck(): Check
    {
        try {
            $response = $this->http(withKey: false)->get('/health');
        } catch (ConnectionException) {
            return Check::fail("not reachable: nothing answered at {$this->root()}. Start it (`--profile vllm` in Compose, on a host with an NVIDIA GPU) and check VLLM_URL in .env. The first start takes minutes: the model downloads and loads.");
        }

        if (! $response->successful()) {
            return Check::fail('/health answered '.$response->status().': the engine is up but not ready, or has died. docker compose logs vllm says which.');
        }

        $model = $this->models()['id'] ?? ($this->provider['model'] ?? '');

        $format = $this->format();

        $revision = filled($this->settings['revision'] ?? null)
            ? 'revision pinned'
            : 'revision NOT pinned (VLLM_REVISION is empty in .env)';

        return Check::ok("/health ok · model {$model}".($format !== '' ? " ({$format})" : '')." · {$revision}");
    }

    private function maxModelLength(): Check
    {
        $length = (int) ($this->models()['max_model_len'] ?? 0);

        if ($length === 0) {
            $length = (int) ($this->settings['max_model_len'] ?? 0);
        }

        if ($length === 0) {
            return Check::warn('/v1/models does not report max_model_len and VLLM_MAX_MODEL_LEN is not set, so the context window cannot be compared with the budget');
        }

        if ($length < $this->budgetWindow) {
            return Check::fail("max-model-len {$length} < budget window {$this->budgetWindow}: vLLM will refuse the longest prompts (400). Start it with --max-model-len {$this->budgetWindow}.");
        }

        $note = $length > $this->budgetWindow * 2
            ? " — far above the window: every sequence may claim that much cache, so fewer fit (Chapter 24, Topic 5). Set it to {$this->budgetWindow}."
            : ' (rejects overflow: ContextTooLong mapped)';

        $line = "max-model-len {$length} ≥ budget window {$this->budgetWindow}{$note}";

        return $length > $this->budgetWindow * 2
            ? Check::warn($line)
            : Check::ok($line);
    }

    /** @return list<Check> */
    private function capacity(): array
    {
        $tokens = $this->kvTokens();

        if ($tokens === 0) {
            return [Check::warn('KV cache size is not in /metrics (vllm:cache_config_info), so the capacity line cannot be read: look for "GPU KV cache size" in the startup log (docker compose logs vllm)')];
        }

        $window = max(1, (int) ($this->models()['max_model_len'] ?? $this->settings['max_model_len'] ?? $this->budgetWindow));

        $concurrency = (int) floor($tokens / $window);

        $checks = [Check::ok(sprintf('KV cache %s tokens · max concurrency ~%d at full window', number_format($tokens), (int) round($tokens / $window)))];

        $checks[] = $concurrency >= $this->queueProcesses
            ? Check::ok("ai queue processes {$this->queueProcesses} (AI_CONCURRENCY) fit the cache: {$concurrency} full-window sequences")
            : Check::warn("ai queue processes {$this->queueProcesses} (AI_CONCURRENCY) > {$concurrency} full-window sequences the cache holds: the extra workers will be preempted or queue inside vLLM. Raise --gpu-memory-utilization, lower --max-model-len, or lower AI_CONCURRENCY.");

        $ceiling = (int) ($this->settings['max_num_seqs'] ?? 0);

        if ($ceiling > 0 && $this->queueProcesses > $ceiling) {
            $checks[] = Check::warn("ai queue processes {$this->queueProcesses} > --max-num-seqs {$ceiling} (VLLM_MAX_NUM_SEQS): the extra workers wait in vLLM's queue");
        }

        return $checks;
    }

    private function prefixCaching(): Check
    {
        $on = $this->gaugeLabel('enable_prefix_caching');

        if ($on === null) {
            return Check::warn('whether prefix caching is on is not in /metrics; start vLLM with --enable-prefix-caching (Chapter 14 stable-first prompts pay for it)');
        }

        return strtolower($on) === 'true'
            ? Check::ok('prefix caching on')
            : Check::warn('prefix caching is OFF: every request pays the system prompt\'s prefill again. Start vLLM with --enable-prefix-caching.');
    }

    // The tool parser is a start-up flag the API does not report: the line is what .env passed to Compose.
    private function toolParser(): Check
    {
        $parser = (string) ($this->settings['tool_parser'] ?? '');

        return $parser !== ''
            ? Check::ok("tool parser {$parser} (VLLM_TOOL_PARSER, from .env; the API does not report it)")
            : Check::warn('VLLM_TOOL_PARSER is empty: without --enable-auto-tool-choice and a --tool-call-parser for the model family, tool calls come back as plain text');
    }

    // vLLM puts the key on /v1/* only; /health and /metrics are public.
    private function auth(): Check
    {
        if (blank($this->provider['api_key'] ?? null)) {
            return Check::warn('VLLM_API_KEY is empty in .env: requests go out with no key. Set one (and the same one on the server) so only the app can use it.');
        }

        try {
            $status = $this->http(withKey: false)->post('/v1/chat/completions', $this->oneToken())->status();
        } catch (ConnectionException) {
            return Check::warn('could not test whether the server refuses requests without a key');
        }

        if (in_array($status, [401, 403], true)) {
            return Check::ok('auth required: a request without the API key is refused');
        }

        return Check::warn("a request with no API key got {$status}, not 401: vLLM was started without --api-key. Anyone who can reach it can use it.");
    }

    private function metricsCheck(): Check
    {
        $body = $this->metricsBody();

        if ($body === '' || ! str_contains($body, 'vllm:')) {
            return Check::warn('/metrics is not available or has no vllm: series; Prometheus (the monitoring profile) will have nothing to scrape');
        }

        $running = $this->gaugeValue('vllm:num_requests_running');
        $waiting = $this->gaugeValue('vllm:num_requests_waiting');

        $load = $running !== null && $waiting !== null
            ? sprintf(' · %d running, %d waiting', $running, $waiting)
            : '';

        return Check::ok('/metrics reachable'.$load);
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

    // The weights' format is in the repo name (FP8, AWQ, GPTQ); the API does not say.
    private function format(): string
    {
        $repo = strtoupper((string) ($this->settings['model_repo'] ?? ($this->models()['root'] ?? '')));

        foreach (['FP8', 'AWQ', 'GPTQ', 'INT4', 'INT8'] as $format) {
            if (str_contains($repo, $format)) {
                return $format;
            }
        }

        return '';
    }

    private function kvTokens(): int
    {
        $blocks = (int) ($this->gaugeLabel('num_gpu_blocks') ?? 0);
        $size = (int) ($this->gaugeLabel('block_size') ?? 0);

        return $blocks * $size;
    }

    private function gaugeLabel(string $label): ?string
    {
        foreach (preg_split('/\R/', $this->metricsBody()) ?: [] as $line) {
            if (! str_starts_with($line, 'vllm:cache_config_info')) {
                continue;
            }

            if (preg_match('/'.preg_quote($label, '/').'="([^"]*)"/', $line, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function gaugeValue(string $name): ?float
    {
        if (preg_match('/^'.preg_quote($name, '/').'(?:\{[^}]*\})?\s+([0-9.eE+-]+)/m', $this->metricsBody(), $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }

    private function metricsBody(): string
    {
        if ($this->metrics !== null) {
            return $this->metrics;
        }

        try {
            $response = $this->http(withKey: false)->get('/metrics');
        } catch (ConnectionException) {
            return $this->metrics = '';
        }

        return $this->metrics = $response->successful()
            ? $response->body()
            : '';
    }

    private function models(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }

        try {
            $listed = $this->http()->get('/v1/models')->json('data') ?? [];
        } catch (Throwable) {
            return $this->models = [];
        }

        $wanted = (string) ($this->provider['model'] ?? '');

        foreach ($listed as $model) {
            if (($model['id'] ?? null) === $wanted) {
                return $this->models = $model;
            }
        }

        return $this->models = $listed[0] ?? [];
    }

    private function oneToken(): array
    {
        return [
            'model' => $this->provider['model'] ?? 'ledgerly-support',
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
