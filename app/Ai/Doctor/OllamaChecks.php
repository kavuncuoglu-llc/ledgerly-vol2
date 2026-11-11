<?php

namespace App\Ai\Doctor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 22, Topic 9: every gotcha of the chapter is a silent one, so each gets a line. The one that fails the build is the context window: a prompt longer than num_ctx is cut from the front, and the system prompt goes first.
final class OllamaChecks implements ServerChecks
{
    private const DEFAULT_NUM_CTX = 4096;                      // what Ollama uses when the Modelfile says nothing

    private const FIVE_MINUTE_DEFAULTS = ['', '5m', '300s', '300'];

    private ?array $tags = null;

    public function __construct(
        private array $provider,
        private PortProbe $ports,
        private int $queueProcesses,
        private int $budgetWindow,
        private float $warmSeconds = 3.0,
    ) {}

    public function title(): string
    {
        return 'Ollama at '.$this->root();
    }

    public function run(): array
    {
        $version = $this->reachable();

        if ($version->status === Check::FAIL) {
            return [$version];
        }

        $models = $this->models();

        return [
            $version,
            $models['check'],
            ...$this->contextWindow($models['present']),
            ...$this->loaded(),
            $this->parallelSlots(),
            $this->keepAlive(),
            $this->publicPort(),
            ...$this->roundTrips(),
            $this->licenses(),
        ];
    }

    public function health(): array
    {
        $version = $this->reachable();

        if ($version->status === Check::FAIL) {
            return [Check::fail('reachable: no · '.$this->unreachableReason()), Check::warn('loaded: unknown · warm: unknown')];
        }

        $chat = (string) $this->provider['model'];

        if (! $this->hasModel($chat)) {
            return [Check::ok('reachable: yes'), Check::fail("loaded: no · the model '{$chat}' does not exist on this server; ollama create it or pull it")];
        }

        $loaded = $this->isLoaded($chat);
        $started = hrtime(true);

        try {
            $this->http()->post('/api/chat', $this->oneToken($chat, native: true))->throw();
        } catch (ConnectionException|RequestException $e) {
            return [Check::ok('reachable: yes'), Check::fail('warm: no · a one-token completion failed: '.$this->reason($e))];
        }

        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        $warm = $seconds <= $this->warmSeconds;

        return [Check::ok(sprintf(
            'reachable: yes · loaded: %s · warm: %s (completion %.2f s)',
            $loaded ? 'yes' : 'no (it loads on the first request)',
            $warm ? 'yes' : 'no',
            $seconds,
        ))];
    }

    private function reachable(): Check
    {
        try {
            $version = $this->http()->get('/api/version')->throw()->json('version');
        } catch (ConnectionException|RequestException $e) {
            return Check::fail('not reachable: '.$this->unreachableReason($e));
        }

        return Check::ok('reachable; version '.($version ?? 'unknown'));
    }

    /** @return array{check: Check, present: list<string>} */
    private function models(): array
    {
        $wanted = $this->wantedModels();
        $missing = array_values(array_filter($wanted, fn (string $model) => ! $this->hasModel($model)));

        if ($missing !== []) {
            return ['check' => Check::fail('models missing: '.implode(', ', array_map(fn (string $model) => $model.' ('.$this->howToGet($model).')', $missing))), 'present' => array_values(array_diff($wanted, $missing))];
        }

        $described = array_map(fn (string $model) => $this->describe($model), $wanted);

        return ['check' => Check::ok('models present: '.implode(', ', $described)), 'present' => $wanted];
    }

    /** @param list<string> $present */
    private function contextWindow(array $present): array
    {
        $model = (string) $this->provider['model'];

        if (! in_array($model, $present, true)) {
            return [];
        }

        $numCtx = $this->numCtx($model);
        $source = $numCtx === null
            ? ' (the Modelfile sets none, so the server default applies)'
            : '';

        $numCtx ??= self::DEFAULT_NUM_CTX;

        if ($numCtx < $this->budgetWindow) {
            return [Check::fail("{$model} num_ctx {$numCtx} < budget window {$this->budgetWindow}{$source}: prompts will be silently truncated. Set num_ctx in the Modelfile.")];
        }

        return [Check::ok("{$model} num_ctx {$numCtx} ≥ budget window {$this->budgetWindow}")];
    }

    private function loaded(): array
    {
        try {
            $loaded = $this->http()->get('/api/ps')->throw()->json('models') ?? [];
        } catch (ConnectionException|RequestException) {
            return [Check::warn('could not read /api/ps, so where the layers went is unknown')];
        }

        if ($loaded === []) {
            return [Check::ok('no model is loaded yet; the first request loads one')];
        }

        return array_map(fn (array $entry) => $this->placement($entry), $loaded);
    }

    private function placement(array $entry): Check
    {
        $name = (string) ($entry['name'] ?? $entry['model'] ?? 'model');
        $size = (float) ($entry['size'] ?? 0);
        $vram = (float) ($entry['size_vram'] ?? 0);
        $gigabytes = $size / 1_073_741_824;

        if ($size <= 0) {
            return Check::warn("{$name} loaded: size unknown");
        }

        if ($vram >= $size) {
            return Check::ok(sprintf('%s loaded: 100%% GPU (%.1f GB)', $name, $gigabytes));
        }

        if ($vram <= 0) {
            return Check::warn(sprintf('%s loaded: 100%% CPU (%.1f GB): no GPU in use. On a Mac or an NVIDIA box this means the GPU was not found.', $name, $gigabytes));
        }

        $gpu = (int) round($vram / $size * 100);

        return Check::warn(sprintf('%s loaded: %d%%/%d%% GPU/CPU — will be slow; consider OLLAMA_MAX_LOADED_MODELS=2 or a smaller model', $name, $gpu, 100 - $gpu));
    }

    // Ollama does not report its own environment. The one place the app can read what the server was told is .env, which Compose also reads.
    private function parallelSlots(): Check
    {
        $slots = config('ai.ollama.server.num_parallel');

        if (blank($slots)) {
            return Check::warn("OLLAMA_NUM_PARALLEL is not set in .env, so the server's slots cannot be compared with the {$this->queueProcesses} ai queue processes. Set it where the server reads it and here.");
        }

        if ((int) $slots !== $this->queueProcesses) {
            return Check::warn("OLLAMA_NUM_PARALLEL={$slots} does not match the {$this->queueProcesses} ai queue processes (AI_CONCURRENCY): more workers than slots just queue inside Ollama, fewer leave slots idle.");
        }

        return Check::ok("OLLAMA_NUM_PARALLEL={$slots} matches ai queue processes ({$this->queueProcesses})");
    }

    private function keepAlive(): Check
    {
        $keepAlive = strtolower(trim((string) config('ai.ollama.server.keep_alive')));

        if ($keepAlive === '') {
            return Check::warn('OLLAMA_KEEP_ALIVE is not set in .env: the server uses its 5m default, and the first reply after a quiet spell pays the model load again. Set 30m.');
        }

        if (in_array($keepAlive, self::FIVE_MINUTE_DEFAULTS, true)) {
            return Check::warn("OLLAMA_KEEP_ALIVE={$keepAlive} is the 5m default: the first reply after a quiet spell pays the model load again. Set 30m.");
        }

        return Check::ok("OLLAMA_KEEP_ALIVE={$keepAlive} (not the 5m default)");
    }

    private function publicPort(): Check
    {
        $host = parse_url($this->root(), PHP_URL_HOST) ?: '';
        $port = (int) (parse_url($this->root(), PHP_URL_PORT) ?: 11434);

        if (! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal'], true)) {
            return Check::ok("port {$port} is on {$host}, not on this machine; check that host's firewall and OLLAMA_HOST there");
        }

        $exposed = $this->ports->exposedOn($port);

        if ($exposed !== []) {
            return Check::warn("port {$port} answers on ".implode(', ', $exposed).': Ollama has no authentication, so anyone who can reach this machine can run inference. Bind it to 127.0.0.1 (OLLAMA_HOST) or firewall it.');
        }

        return Check::ok("port {$port} not bound on a public interface");
    }

    /** @return list<Check> */
    private function roundTrips(): array
    {
        $model = (string) $this->provider['model'];

        if (! $this->hasModel($model)) {
            return [];
        }

        $checks = [];

        $openAi = $this->timed(fn () => $this->http()->post('/v1/chat/completions', $this->oneToken($model, native: false))->throw());
        $native = $this->timed(fn () => $this->http()->post('/api/chat', $this->oneToken($model, native: true))->throw());

        if ($openAi['error'] !== null || $native['error'] !== null) {
            return [Check::fail('round trip failed: '.($openAi['error'] ?? '/v1 ok').' · '.($native['error'] ?? '/api ok'))];
        }

        $exact = isset($native['response']->json()['prompt_eval_count']);

        $line = sprintf('/v1/chat/completions round-trip %.1f s · /api/chat round-trip %.1f s%s', $openAi['seconds'], $native['seconds'], $exact ? ' with exact counts' : ' (no exact counts in the answer)');

        $checks[] = $exact
            ? Check::ok($line)
            : Check::warn($line);

        return $checks;
    }

    private function licenses(): Check
    {
        $missing = [];

        foreach (config('ai.providers') as $name => $provider) {
            if (in_array($provider['driver'] ?? '', ['fake'], true)) {
                continue;
            }

            if (blank($provider['license'] ?? null)) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            return Check::warn('license not recorded for '.implode(', ', $missing).' (Chapter 20: config/ai.php)');
        }

        return Check::ok('license recorded for all providers');
    }

    /** @return list<string> the chat model, then the embedding model, then the vision model, once each */
    private function wantedModels(): array
    {
        return array_values(array_unique(array_filter([
            (string) $this->provider['model'],
            (string) config('ai.embed_model'),
            (string) config('ai.vision_model'),
        ])));
    }

    private function hasModel(string $model): bool
    {
        foreach ($this->tags() as $entry) {
            $name = (string) ($entry['name'] ?? $entry['model'] ?? '');

            if ($name === $model) {
                return true;
            }

            if ($name === $model.':latest') {
                return true;
            }
        }

        return false;
    }

    private function howToGet(string $model): string
    {
        return str_starts_with($model, 'ledgerly-support')
            ? "ollama create {$model} -f ops/ollama/Modelfile.{$model}"
            : "ollama pull {$model}";
    }

    private function describe(string $model): string
    {
        $show = $this->show($model);

        $parent = (string) ($show['details']['parent_model'] ?? '');

        if ($parent !== '') {
            return "{$model} ({$parent} + params)";
        }

        $details = array_filter([
            $show['details']['family'] ?? null,
            $show['details']['parameter_size'] ?? null,
            $show['details']['quantization_level'] ?? null,
        ]);

        return $details === []
            ? $model
            : "{$model} (".implode(' ', $details).')';
    }

    // The Modelfile's num_ctx as the server will use it; null when the Modelfile does not set one
    private function numCtx(string $model): ?int
    {
        $show = $this->show($model);

        if (isset($show['parameters_map']['num_ctx'])) {
            return (int) $show['parameters_map']['num_ctx'];
        }

        if (preg_match('/^\s*num_ctx\s+(\d+)/m', (string) ($show['parameters'] ?? ''), $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function show(string $model): array
    {
        try {
            return $this->http()->post('/api/show', ['model' => $model, 'name' => $model])->throw()->json() ?? [];
        } catch (ConnectionException|RequestException) {
            return [];
        }
    }

    private function isLoaded(string $model): bool
    {
        try {
            $loaded = $this->http()->get('/api/ps')->throw()->json('models') ?? [];
        } catch (ConnectionException|RequestException) {
            return false;
        }

        foreach ($loaded as $entry) {
            $name = (string) ($entry['name'] ?? $entry['model'] ?? '');

            if ($name === $model || $name === $model.':latest') {
                return true;
            }
        }

        return false;
    }

    private function tags(): array
    {
        if ($this->tags !== null) {
            return $this->tags;
        }

        try {
            return $this->tags = $this->http()->get('/api/tags')->throw()->json('models') ?? [];
        } catch (ConnectionException|RequestException) {
            return $this->tags = [];
        }
    }

    private function oneToken(string $model, bool $native): array
    {
        $messages = [['role' => 'user', 'content' => 'Reply with one word: ready.']];

        return $native
            ? ['model' => $model, 'messages' => $messages, 'stream' => false, 'options' => ['num_predict' => 1, 'temperature' => 0]]
            : ['model' => $model, 'messages' => $messages, 'stream' => false, 'max_tokens' => 1, 'temperature' => 0];
    }

    /** @return array{seconds: float, error: ?string, response: mixed} */
    private function timed(callable $call): array
    {
        $started = hrtime(true);

        try {
            $response = $call();
        } catch (ConnectionException|RequestException $e) {
            return ['seconds' => 0.0, 'error' => $this->reason($e), 'response' => null];
        }

        return ['seconds' => (hrtime(true) - $started) / 1_000_000_000, 'error' => null, 'response' => $response];
    }

    private function unreachableReason(?Throwable $e = null): string
    {
        $reason = $e === null
            ? ''
            : ' ('.$this->reason($e).')';

        return "nothing answered at {$this->root()}{$reason}. Start Ollama (`ollama serve`, the macOS app, or `--profile ollama` in Compose) and check OLLAMA_URL in .env.";
    }

    private function reason(Throwable $e): string
    {
        $message = trim(strtok($e->getMessage(), "\n") ?: $e::class);

        return trim((string) preg_replace('/ \(see https?:\/\/\S+\)| for https?:\/\/\S+$/', '', $message));
    }

    private function root(): string
    {
        return preg_replace('#/v1/?$#', '', rtrim((string) $this->provider['base_url'], '/'));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->root())
            ->withToken((string) ($this->provider['api_key'] ?? ''))
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout((int) ($this->provider['timeout'] ?? 120));
    }
}
