<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Doctor\Check;
use App\Ai\Doctor\LlamaServerChecks;
use App\Ai\Doctor\LocalDigests;
use App\Ai\Doctor\OllamaChecks;
use App\Ai\Doctor\PortProbe;
use App\Ai\Doctor\ProfileChecks;
use App\Ai\Doctor\ServerChecks;
use App\Ai\Doctor\TritonChecks;
use App\Ai\Doctor\VllmChecks;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Hardware\HostProbe;
use App\Ai\Hardware\ModelShape;
use App\Ai\Hardware\ReachableMemory;
use App\Ai\Hardware\ShapeResolver;
use App\Ai\Hardware\Sizing;
use App\Ai\Hardware\SizingSum;
use App\Ai\Health\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AiDoctor extends Command
{
    protected $signature = 'ai:doctor
        {--provider=local : The provider (or tier) whose server to check: local, local-llama, vllm, ...}
        {--local : The digest of every local model against the one on record: config for a base model, the registry for a fine-tune (Chapter 50)}
        {--health : Only reachable, loaded and warm (and the circuit breaker): the probe a deploy script or a readiness check runs}
        {--profile= : Check a Compose profile (ollama, llama, vllm, triton, monitoring) against this machine before you start it}
        {--size : Do the sizing sum for a model: weights + KV cache + headroom against the memory this machine can give a model}
        {--model= : The model to size (default: AI_MODEL)}
        {--ctx= : Context per slot in tokens (default: AI_CONTEXT_WINDOW)}
        {--slots=2 : Parallel slots}
        {--cache=fp16 : KV cache type: fp16, q8_0 or q4_0}
        {--reachable= : GPU-reachable memory in GB, when this machine cannot be read}
        {--weights= : Weights in GB, when neither the server nor the tag can say}
        {--layers= : Layers, for a model the catalogue does not know}
        {--kv-heads= : KV heads, same}
        {--head-dim= : Head dimension, same}';

    protected $description = 'Check the model server the app depends on: models, context window, placement, slots, keep-alive, exposure, round trips (Chapter 22); --size does the sizing sum (Chapter 21)';

    public function handle(AiManager $ai, ShapeResolver $resolver, SizingSum $sum, HostProbe $host): int
    {
        if ($this->option('size')) {
            return $this->size($resolver, $sum, $host);
        }

        if ($this->option('local')) {
            return $this->local(app(LocalDigests::class));
        }

        if ($this->option('profile')) {
            return $this->profile($host);
        }

        $name = (string) (config('ai.tiers')[$this->option('provider')] ?? $this->option('provider'));

        try {
            $provider = $ai->config($name);
        } catch (ProviderNotConfigured $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $ai->configured($name)) {
            $this->error("The '{$name}' provider is not set up. `php artisan ai:providers` shows which are.");

            return self::FAILURE;
        }

        if ($provider['hosted'] ?? false) {
            $this->error("'{$name}' is a hosted provider. ai:doctor checks servers you run; it does not probe someone else's API.");

            return self::FAILURE;
        }

        $checks = $this->checksFor($provider);

        if ($checks === null) {
            $this->error("ai:doctor has no checks for the '{$name}' server type yet.");

            return self::FAILURE;
        }

        return $this->report($checks, $name);
    }

    private function checksFor(array $provider): ?ServerChecks
    {
        return match ($provider['native'] ?? null) {
            'ollama' => new OllamaChecks($provider, app(PortProbe::class), (int) config('ai.concurrency'), (int) config('ai.budget.window'), (float) config('ai.health.warm_seconds', 3)),
            'llama-server' => new LlamaServerChecks($provider, (int) config('ai.concurrency'), (int) config('ai.budget.window'), (string) config('ai.token_counter'), (float) config('ai.health.warm_seconds', 3)),
            'vllm' => new VllmChecks($provider, (int) config('ai.concurrency'), (int) config('ai.budget.window'), (array) config('ai.vllm'), (float) config('ai.health.warm_seconds', 3)),
            'triton' => new TritonChecks($provider, (float) config('ai.health.warm_seconds', 3)),
            default => null,
        };
    }

    private function report(ServerChecks $server, string $name): int
    {
        $this->line($server->title());

        $checks = $this->option('health')
            ? $this->withOperationalState($server->health(), $name)
            : $server->run();

        foreach ($checks as $check) {
            $this->line('  '.$check->symbol().' '.$check->message);
        }

        $warnings = count(array_filter($checks, fn (Check $check) => $check->status === Check::WARN));
        $failures = count(array_filter($checks, fn (Check $check) => $check->status === Check::FAIL));

        if (! $this->option('health')) {
            $this->line(sprintf('%d warning%s, %d failure%s', $warnings, $warnings === 1 ? '' : 's', $failures, $failures === 1 ? '' : 's'));
        }

        return $failures > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    // Chapter 25, Topic 7: the health line gains what the operations layer knows and the server cannot say: when ai:warm last ran, and whether the circuit breaker has the server marked down.
    /** @param list<Check> $checks @return list<Check> */
    private function withOperationalState(array $checks, string $name): array
    {
        if ($checks === []) {
            return $checks;
        }

        $last = array_pop($checks);

        $suffix = '';

        $warm = Cache::get(AiWarm::CACHE_KEY.$name);

        if (is_array($warm) && isset($warm['at'])) {
            $suffix .= ' · ai-warm ran at '.CarbonImmutable::parse($warm['at'])->format('H:i:s');
        }

        $suffix .= ' · breaker '.$this->breakerState($name);

        $checks[] = new Check($last->status, $last->message.$suffix);

        return $checks;
    }

    private function breakerState(string $name): string
    {
        $breaker = app(CircuitBreaker::class);

        $roles = [$name];

        if ($name !== 'local' && $this->sameServer('local', $name)) {
            $roles[] = 'local';                                                    // the app calls the role, so the role's breaker is the one that matters for this server
        }

        foreach ($roles as $role) {
            if ($breaker->state($role) !== CircuitBreaker::CLOSED) {
                return $breaker->describe($role);
            }
        }

        return 'closed';
    }

    private function sameServer(string $a, string $b): bool
    {
        try {
            return rtrim((string) app(AiManager::class)->config($a)['base_url'], '/') === rtrim((string) app(AiManager::class)->config($b)['base_url'], '/');
        } catch (ProviderNotConfigured) {
            return false;
        }
    }

    private function local(LocalDigests $digests): int
    {
        $rows = $digests->rows();

        foreach ($rows as $row) {
            $this->line($row->line());
        }

        if ($rows === []) {
            $this->line('No local model is configured with an Ollama server.');
        }

        return collect($rows)->contains(fn ($row) => $row->isProblem())
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function profile(HostProbe $host): int
    {
        $profile = (string) $this->option('profile');

        $checks = new ProfileChecks(
            host: $host,
            env: fn (string $name): string => (string) env($name),
            portInUse: function (int $port): bool {
                $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.3);

                if ($socket === false) {
                    return false;
                }

                fclose($socket);

                return true;
            },
            basePath: base_path(),
        );

        $this->line($checks->title($profile));

        $results = $checks->run($profile);

        foreach ($results as $check) {
            $this->line('  '.$check->symbol().' '.$check->message);
        }

        $failures = count(array_filter($results, fn (Check $check) => $check->status === Check::FAIL));
        $warnings = count(array_filter($results, fn (Check $check) => $check->status === Check::WARN));

        $this->line(sprintf('%d warning%s, %d failure%s', $warnings, $warnings === 1 ? '' : 's', $failures, $failures === 1 ? '' : 's'));

        return $failures > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function size(ShapeResolver $resolver, SizingSum $sum, HostProbe $host): int
    {
        $model = (string) ($this->option('model') ?: config('ai.model'));
        $context = (int) ($this->option('ctx') ?: config('ai.budget.window'));
        $slots = max(1, (int) $this->option('slots'));
        $cache = strtolower((string) $this->option('cache'));

        if (! in_array($cache, ModelShape::cacheTypes(), true)) {
            $this->error("Unknown cache type '{$cache}'. Use one of: ".implode(', ', ModelShape::cacheTypes()).'.');

            return self::FAILURE;
        }

        $shape = $resolver->resolve($model, $this->overrides());

        if (! $shape->hasAttentionShape()) {
            $this->error("Cannot size the cache for {$model}: its layers, KV heads and head dimension are unknown. Start the server so it can say, or pass --layers, --kv-heads and --head-dim.");

            return self::FAILURE;
        }

        if ($shape->weightsBytes() <= 0) {
            $this->error("Cannot size the weights for {$model}: no size in the tag. Pass --weights=GB.");

            return self::FAILURE;
        }

        $reachable = $this->reachable($host);

        if ($reachable === null) {
            $this->error('Cannot read how much memory this machine gives a model. Pass --reachable=GB.');

            return self::FAILURE;
        }

        $sizing = $sum->compute($shape, $context, $slots, $cache);

        $this->line($this->summary($sizing));
        $this->line(sprintf('reachable on this machine: %.1f GB (%s)', $reachable[0], $reachable[1]));

        $this->line(sprintf('%s · %s · %d slot%s × %d tokens', $model, $this->sourceNote($shape), $slots, $slots === 1 ? '' : 's', $context));

        if ($sizing->fitsIn($reachable[0])) {
            $this->info(sprintf('✓ fits with %.1f GB to spare', $sizing->spareGb($reachable[0])));

            return self::SUCCESS;
        }

        $this->error(sprintf('✗ does not fit: %.1f GB needed, %.1f GB reachable. In order of cost:', $sizing->totalGb, $reachable[0]));

        foreach ($sum->remedies($shape, $sizing, $reachable[0]) as $remedy) {
            $this->line('  - '.$remedy);
        }

        return self::FAILURE;
    }

    private function summary(Sizing $sizing): string
    {
        $cache = $sizing->cache === 'fp16'
            ? sprintf('%.1f GB (fp16; %.1f GB at q8_0)', $sizing->kvGb, $sizing->kvQ8Gb)
            : sprintf('%.1f GB (%s)', $sizing->kvGb, $sizing->cache);

        return sprintf('weights %.1f GB · KV cache %s · headroom %.1f GB · total %.1f GB', $sizing->weightsGb, $cache, $sizing->headroomGb, $sizing->totalGb);
    }

    private function sourceNote(ModelShape $shape): string
    {
        return match ($shape->source) {
            'ollama' => sprintf('read from the server (%s, %s)', $this->billions($shape), $shape->quantization),
            'catalog' => sprintf('estimated from the tag (%s, %s)', $this->billions($shape), $shape->quantization),
            default => 'estimated',
        };
    }

    private function billions(ModelShape $shape): string
    {
        return rtrim(rtrim(number_format($shape->parametersBillions, 1), '0'), '.').'B';
    }

    private function reachable(HostProbe $host): ?array
    {
        if ($this->option('reachable') !== null) {
            return [(float) $this->option('reachable'), 'from --reachable'];
        }

        return (new ReachableMemory($host))->detect();
    }

    private function overrides(): array
    {
        $overrides = [];

        foreach (['layers' => 'layers', 'kv-heads' => 'kv_heads', 'head-dim' => 'head_dim'] as $option => $key) {
            if ($this->option($option) !== null) {
                $overrides[$key] = (int) $this->option($option);
            }
        }

        if ($this->option('weights') !== null) {
            $overrides['weights_bytes'] = (float) $this->option('weights') * ModelShape::BYTES_PER_GIB;
        }

        return $overrides;
    }
}
