<?php

namespace App\Ai;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Decorators\RetryingEmbeddings;
use App\Ai\Decorators\RetryingModel;
use App\Ai\Decorators\TracingModel;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Health\ProviderHealth;
use App\Ai\Retrieval\SidecarReranker;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Testing\FakeAi;
use Closure;

// Chapter 19, Topic 3: role names in config/ai.php resolve to decorated drivers, the way Cache::store('redis') and Storage::disk('s3') do. Features say which name; one file says what it is.
final class AiManager
{
    /** @var array<string, ChatModel> */
    private array $resolved = [];

    private ?FakeAi $fake = null;

    private bool $faking = false;

    public function chat(?string $name = null): ChatModel
    {
        $name ??= (string) config('ai.default');

        return $this->resolved[$name] ??= $this->decorate($name, $this->makeDriver($name));
    }

    public function for(string $feature): ChatModel
    {
        return $this->chat($this->failingOver($feature, $this->providerFor($feature)));
    }

    public function providerFor(string $feature): string
    {
        return (string) ($this->featureSettings($feature)['provider'] ?? config('ai.default'));
    }

    // The provider a feature may escalate to, or null when none is named or the named one has not been set up (frontier is unset by default and nothing leaves the machine until it is).
    public function escalationFor(string $feature): ?string
    {
        $target = $this->featureSettings($feature)['escalate_to'] ?? null;

        if ($target === null) {
            return null;
        }

        return $this->configured($target)
            ? (string) $target
            : null;
    }

    // Chapter 25, Topic 7: when the server behind a role is down (its circuit is open) and AI_FALLBACK_LOCAL names a fallback for this feature, the work goes there. Otherwise the role is kept and the call fails at once with CircuitOpen.
    private function failingOver(string $feature, string $provider): string
    {
        if ($this->faking) {
            return $provider;
        }

        $health = app(ProviderHealth::class);

        if ($health->isAvailable($provider)) {
            return $provider;
        }

        return $health->fallbackFor($provider, $feature) ?? $provider;
    }

    // Feature names contain dots, so they are array keys, not config paths.
    public function featureSettings(string $feature): array
    {
        return config('ai.features')[$feature] ?? [];
    }

    public function embed(?string $name = null): EmbeddingModel
    {
        $name ??= (string) config('ai.embedding_provider', 'local-embed');

        if ($this->faking) {
            return new FakeDriver($name, $this->fake());
        }

        $config = $this->config($name);

        return new RetryingEmbeddings($this->makeDriver($name, $config));
    }

    public function reranker(?string $name = null): Reranker
    {
        return new SidecarReranker(app(SidecarClient::class));
    }

    public function configured(string $name): bool
    {
        $config = $this->resolved($name);

        if ($config === null) {
            return false;
        }

        if (($config['driver'] ?? null) === 'fake') {
            return true;
        }

        foreach (['base_url', 'model'] as $required) {
            if (blank($config[$required] ?? null)) {
                return false;
            }
        }

        return $name !== 'frontier'
            || filled($config['api_key'] ?? null);                                 // a hosted provider without a key is not set up
    }

    /** @return array<string, array> */
    public function providers(): array
    {
        return collect(array_keys(config('ai.providers')))->mapWithKeys(fn (string $name) => [$name => ($this->resolved($name) ?? []) + ['name' => $name]])->all();
    }

    public function config(string $name): array
    {
        $config = $this->resolved($name) ?? throw ProviderNotConfigured::unknown($name);

        return $config + ['name' => $name, 'connect_timeout' => config('ai.connect_timeout', 2)];
    }

    // The model a provider name is serving, whichever server the name points at today.
    public function model(string $name): string
    {
        return (string) ($this->resolved($name)['model'] ?? '');
    }

    // Chapter 23, Topic 9: a role can be an alias, '@local-llama', so `local` follows AI_LOCAL_PROVIDER and nothing above the manager notices. The alias keeps the role's name in model_calls.
    private function resolved(string $name): ?array
    {
        $config = config('ai.providers')[$name] ?? null;

        for ($hops = 0; is_string($config) && str_starts_with($config, '@') && $hops < 5; $hops++) {
            $config = config('ai.providers')[substr($config, 1)] ?? null;
        }

        return is_array($config)
            ? $config
            : null;
    }

    public function fake(): FakeAi
    {
        $this->faking = true;
        $this->resolved = [];

        return $this->fake ??= new FakeAi;
    }

    public function forget(): void
    {
        $this->resolved = [];
    }

    public function assertSent(Closure $matches): void
    {
        $this->fake()->assertSent($matches);
    }

    public function assertSentCount(string $feature, int $times): void
    {
        $this->fake()->assertSentCount($feature, $times);
    }

    public function assertNothingSentTo(string $provider): void
    {
        $this->fake()->assertNothingSentTo($provider);
    }

    public function assertNothingSent(): void
    {
        $this->fake()->assertNothingSent();
    }

    private function makeDriver(string $name, ?array $config = null): ChatModel&EmbeddingModel
    {
        $config ??= $this->config($name);

        return match ($config['driver'] ?? null) {
            'openai-compatible' => $this->openAiCompatible($name, $config),
            'fake' => new FakeDriver($name, $this->fake(), (int) ($config['dimensions'] ?? 768)),
            null => throw ProviderNotConfigured::unknown($name),
            default => throw ProviderNotConfigured::unsupportedDriver($name, (string) $config['driver']),
        };
    }

    private function openAiCompatible(string $name, array $config): OpenAiCompatibleDriver
    {
        foreach (['base_url', 'model'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw ProviderNotConfigured::incomplete($name, $required);
            }
        }

        if ($name === 'frontier' && blank($config['api_key'] ?? null)) {
            throw ProviderNotConfigured::incomplete($name, 'api_key');
        }

        return new OpenAiCompatibleDriver($config);
    }

    // Cross-cutting, once, for every driver. Outermost first: one span, and usage logged once, however many attempts the retries took.
    private function decorate(string $name, ChatModel $driver): ChatModel
    {
        if ($this->faking) {
            return new TracingModel(new ProfileMergingModel(new FakeDriver($name, $this->fake())));  // a fake logs nothing and never sleeps, but a test can still assert on the merged profile, and on its span (Chapter 31)
        }

        return new TracingModel(new UsageLoggingModel(new RetryingModel(new ProfileMergingModel($driver))));      // tracing outermost: its span is the current one when usage is logged (Chapter 31)
    }
}
