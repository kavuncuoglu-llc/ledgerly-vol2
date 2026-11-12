<?php

namespace App\Ai;

use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Decorators\BudgetedModel;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Decorators\RedactingModel;
use App\Ai\Decorators\RetryingEmbeddings;
use App\Ai\Decorators\RetryingModel;
use App\Ai\Decorators\TracingModel;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\AiSdkDriver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Drivers\PrismDriver;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Health\ProviderHealth;
use App\Ai\Ledger\Costing;
use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Privacy\VaultFactory;
use App\Ai\Retrieval\SidecarReranker;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Testing\FakeAi;
use Closure;
use Illuminate\Support\Facades\Log;

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
        return $this->chat($this->failingOver($feature, $this->allowed($feature, $this->providerFor($feature))));
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

        if (! $this->mayReceive($feature, (string) $target)) {
            return null;                                                           // Chapter 34, Topic 2: a provider the feature does not allow is never an escalation target
        }

        return $this->configured($target)
            ? (string) $target
            : null;
    }

    // Chapter 36, Topic 5, rung 1: the next provider down the tiers (frontier, mid, small) that is set up and that the feature may use, or null when this one is already the cheapest.
    public function cheaperThan(string $provider, ?string $feature = null): ?string
    {
        $order = array_values(array_filter([config('ai.tiers.frontier'), config('ai.tiers.mid'), config('ai.tiers.small')]));

        $position = array_search($provider, $order, true);

        if ($position === false) {
            return null;
        }

        foreach (array_slice($order, $position + 1) as $candidate) {
            if (! $this->configured($candidate)) {
                continue;
            }

            if ($feature !== null && ! $this->mayReceive($feature, $candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    // Chapter 34, Topic 2: may this feature's data go to this provider? The feature's list of allowed providers, and the answers recorded beside the provider that the feature requires (a signed DPA, for example).
    public function mayReceive(string $feature, string $provider): bool
    {
        $settings = $this->featureSettings($feature);

        $allowed = $settings['allowed_providers'] ?? null;

        if (is_array($allowed) && ! in_array($provider, $allowed, true)) {
            return false;
        }

        foreach ($settings['requires'] ?? [] as $answer) {
            if (! ($this->resolved($provider)['privacy'][$answer] ?? false)) {
                return false;
            }
        }

        return true;
    }

    // A feature configured onto a provider it may not use goes to the local role instead: nothing leaves the machine because of a config slip.
    private function allowed(string $feature, string $provider): string
    {
        if ($this->mayReceive($feature, $provider)) {
            return $provider;
        }

        Log::warning('ai.provider_not_allowed', ['feature' => $feature, 'provider' => $provider, 'used' => 'local']);

        return 'local';
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

        $driver = $this->makeDriver($name, $config);

        if (! $driver instanceof EmbeddingModel) {
            throw ProviderNotConfigured::unsupportedDriver($name, (string) ($config['driver'] ?? ''));          // Chapter 47: the package drivers take chat; embeddings stay on the raw driver
        }

        return new RetryingEmbeddings($driver);
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

    private function makeDriver(string $name, ?array $config = null): ChatModel
    {
        $config ??= $this->config($name);

        return match ($config['driver'] ?? null) {
            'openai-compatible' => $this->openAiCompatible($name, $config),
            'prism' => new PrismDriver($this->complete($name, $config)),
            'ai-sdk' => new AiSdkDriver($this->complete($name, $config)),
            'fake' => new FakeDriver($name, $this->fake(), (int) ($config['dimensions'] ?? 768)),
            null => throw ProviderNotConfigured::unknown($name),
            default => throw ProviderNotConfigured::unsupportedDriver($name, (string) $config['driver']),
        };
    }

    private function openAiCompatible(string $name, array $config): OpenAiCompatibleDriver
    {
        return new OpenAiCompatibleDriver($this->complete($name, $config));
    }

    private function complete(string $name, array $config): array
    {
        foreach (['base_url', 'model'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw ProviderNotConfigured::incomplete($name, $required);
            }
        }

        if ($name === 'frontier' && blank($config['api_key'] ?? null)) {
            throw ProviderNotConfigured::incomplete($name, 'api_key');
        }

        return $config;
    }

    // Cross-cutting, once, for every driver. Outermost first: one span, redaction inside it and outside the usage log (Chapter 34), and usage logged once, however many attempts the retries took.
    private function decorate(string $name, ChatModel $driver): ChatModel
    {
        if ($this->faking) {
            return new TracingModel($this->budgeted($name, $this->redacting($name, new ProfileMergingModel(new FakeDriver($name, $this->fake())))));  // a fake logs nothing and never sleeps, but a test can still assert on the merged profile, and on its span (Chapter 31)
        }

        return new TracingModel($this->budgeted($name, $this->redacting($name, new UsageLoggingModel(new RetryingModel(new ProfileMergingModel($driver))))));      // tracing outermost: its span is the current one when usage is logged (Chapter 31)
    }

    // Chapter 36: the budget decorator sits just inside tracing and outside redaction, so a refused call never reaches the redactor and the budget span is the current one. Off (the default in the test suite) it is not in the chain at all.
    private function budgeted(string $name, ChatModel $inner): ChatModel
    {
        if (! config('ai.budgets.enabled')) {
            return $inner;
        }

        return new BudgetedModel($inner, app(BudgetGuard::class), app(KillSwitch::class), app(Counters::class), app(Costing::class), $name, (string) ($this->resolved($name)['model'] ?? ''));
    }

    private function redacting(string $name, ChatModel $inner): ChatModel
    {
        return new RedactingModel($inner, app(PersonalDataDetector::class), app(VaultFactory::class), $this->resolved($name) ?? []);
    }
}
