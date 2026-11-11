<?php

namespace App\Ai\Doctor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 23, Topic 9: llama-server's own checks. Where its API cannot say something (how many layers went to the GPU), the line says what .env asked for and where the server prints the truth.
final class LlamaServerChecks implements ServerChecks
{
    private ?array $slots = null;

    public function __construct(
        private array $provider,
        private int $queueProcesses,
        private int $budgetWindow,
        private string $tokenCounter,
        private float $warmSeconds = 3.0,
    ) {}

    public function title(): string
    {
        return 'llama-server at '.$this->root();
    }

    public function run(): array
    {
        $health = $this->healthCheck();

        if ($health->status === Check::FAIL) {
            return [$health];
        }

        return [
            $health,
            $this->auth(),
            ...$this->slotChecks(),
            $this->offload(),
            $this->metrics(),
            $this->tokenize(),
            $this->roundTrip(),
            $this->licenses(),
        ];
    }

    public function health(): array
    {
        $health = $this->healthCheck();

        if ($health->status === Check::FAIL) {
            return [Check::fail('reachable: '.$this->reachability().' · loaded: unknown · warm: unknown')];
        }

        $started = hrtime(true);

        try {
            $this->http()->post('/v1/chat/completions', $this->oneToken())->throw();
        } catch (ConnectionException|RequestException $e) {
            return [Check::ok('reachable: yes · loaded: yes'), Check::fail('warm: no · a one-token completion failed: '.$this->reason($e))];
        }

        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        return [Check::ok(sprintf('reachable: yes · loaded: yes · warm: %s (completion %.2f s)', $seconds <= $this->warmSeconds ? 'yes' : 'no', $seconds))];
    }

    private function healthCheck(): Check
    {
        try {
            $response = $this->http(withKey: false)->get('/health');
        } catch (ConnectionException $e) {
            return Check::fail("not reachable: nothing answered at {$this->root()}. Start it (`--profile llama` in Compose, after ./ops/llama/pull.sh) and check LLAMA_URL in .env.");
        }

        if ($response->status() === 503) {
            return Check::fail('/health 503: the server is up but still loading the model. Wait for the startup log to say it is listening, then run this again.');
        }

        if (! $response->successful()) {
            return Check::fail('/health answered '.$response->status());
        }

        $build = $this->props()['build_info'] ?? null;

        return Check::ok('/health ok'.($build ? " · Build {$build}" : ''));
    }

    // The key is the thing Ollama does not have. A request without it must be refused: an empty body gets 401 from a server with a key and 400 from one without.
    private function auth(): Check
    {
        if (blank($this->provider['api_key'] ?? null)) {
            return Check::warn('LLAMA_API_KEY is empty in .env: requests go out with no key. Set one (and the same one on the server) so only the app can use it.');
        }

        try {
            $status = $this->http(withKey: false)->post('/v1/chat/completions', [])->status();
        } catch (ConnectionException) {
            return Check::warn('could not test whether the server refuses requests without a key');
        }

        if (in_array($status, [401, 403], true)) {
            return Check::ok('requests without the API key are refused');
        }

        return Check::warn("a request with no API key got {$status}, not 401: the server was started without --api-key. Anyone who can reach it can use it.");
    }

    /** @return list<Check> */
    private function slotChecks(): array
    {
        $slots = $this->slots();

        if ($slots === []) {
            $total = $this->props()['total_slots'] ?? null;

            return [Check::warn('/slots is not available (the server was started with --no-slots?), so the context per slot cannot be read'.($total ? "; /props says {$total} slots" : ''))];
        }

        $count = count($slots);
        $context = (int) ($slots[0]['n_ctx'] ?? 0);

        $checks = [
            $count === $this->queueProcesses
                ? Check::ok("slots {$count} = ai queue processes {$this->queueProcesses}")
                : Check::warn("slots {$count} ≠ ai queue processes {$this->queueProcesses} (AI_CONCURRENCY): more workers than slots just queue inside llama-server (-np)"),
        ];

        $checks[] = $context >= $this->budgetWindow
            ? Check::ok("context per slot {$context} ≥ budget window {$this->budgetWindow}")
            : Check::fail("context per slot {$context} < budget window {$this->budgetWindow}: prompts will be cut or refused. -c is the total shared by every slot; set it to slots × window (for example -c ".($count * $this->budgetWindow)." -np {$count}).");

        return $checks;
    }

    // The API does not report -ngl; the startup log does ("offloaded 33/33 layers to GPU"). What can be checked is what .env asked for.
    private function offload(): Check
    {
        $ngl = config('ai.llama.ngl');

        if ($ngl === null || $ngl === '') {
            return Check::warn('LLAMA_NGL is not set in .env, so whether every layer is on the GPU cannot be confirmed here: look for "offloaded N/N layers to GPU" in the startup log (docker compose logs llama).');
        }

        if ((int) $ngl === 0) {
            return Check::ok('-ngl 0: CPU profile, no layers on a GPU (Figure 23-8)');
        }

        if ((int) $ngl >= 99) {
            return Check::ok('-ngl '.(int) $ngl.': all layers requested on the GPU (confirm "offloaded N/N" in the startup log; /props does not report it)');
        }

        return Check::warn('-ngl '.(int) $ngl.': only some layers are on the GPU, and the rest run at CPU speed (Chapter 22\'s spill rule). Fit the whole model or accept it.');
    }

    private function metrics(): Check
    {
        try {
            $response = $this->http()->get('/metrics');
        } catch (ConnectionException) {
            return Check::warn('/metrics did not answer');
        }

        if (! $response->successful() || ! str_contains($response->body(), 'llamacpp:')) {
            return Check::warn('/metrics is not available: start llama-server with --metrics for Prometheus and the cache usage line');
        }

        $usage = preg_match('/llamacpp:kv_cache_usage_ratio\s+([0-9.eE+-]+)/', $response->body(), $match) === 1
            ? sprintf(' · kv cache %.0f%% used', ((float) $match[1]) * 100)
            : '';

        $type = config('ai.llama.cache_type');
        $note = $type
            ? " · kv cache type {$type} (from .env)"
            : '';

        return Check::ok('/metrics reachable'.$usage.$note);
    }

    private function tokenize(): Check
    {
        try {
            $tokens = $this->http()->post('/tokenize', ['content' => 'Invoice #4471 is overdue by 30 days.'])->throw()->json('tokens');
        } catch (ConnectionException|RequestException $e) {
            return Check::warn('/tokenize failed: '.$this->reason($e));
        }

        if (! is_array($tokens)) {
            return Check::warn('/tokenize answered without a tokens list');
        }

        return $this->tokenCounter === 'server'
            ? Check::ok('/tokenize exact counter active ('.count($tokens).' tokens for the test sentence)')
            : Check::warn("/tokenize works, but AI_TOKEN_COUNTER={$this->tokenCounter}: the budget math is an estimate. Set AI_TOKEN_COUNTER=server.");
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

    private function props(): array
    {
        try {
            return $this->http()->get('/props')->json() ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    private function slots(): array
    {
        if ($this->slots !== null) {
            return $this->slots;
        }

        try {
            $response = $this->http()->get('/slots');
        } catch (ConnectionException) {
            return $this->slots = [];
        }

        return $this->slots = $this->usable($response)
            ? array_values($response->json() ?? [])
            : [];
    }

    private function usable(Response $response): bool
    {
        return $response->successful() && is_array($response->json());
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

    private function reachability(): string
    {
        return "no · nothing answered at {$this->root()}";
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
            ->timeout((int) ($this->provider['timeout'] ?? 120));

        return $withKey
            ? $request->withToken((string) ($this->provider['api_key'] ?? ''))
            : $request;
    }
}
