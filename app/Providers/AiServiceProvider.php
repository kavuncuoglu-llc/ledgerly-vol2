<?php

namespace App\Providers;

use App\Ai\Batch\FakeBatchDriver;
use App\Ai\Budgets\ArrayCounters;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\RedisCounters;
use App\Ai\Events\BudgetThresholdReached;
use App\Ai\Events\EmergencyKillSwitchActivated;
use App\Ai\Events\GuardrailBreached;
use App\Ai\Events\ToolCalled;
use App\Ai\FineTuning\FineTuneEnded;
use App\Ai\FineTuning\FineTuneSucceeded;
use App\Ai\Listeners\AnnounceKillSwitch;
use App\Ai\Listeners\NotifyBudgetThreshold;
use App\Ai\Listeners\NotifyFineTuneFinished;
use App\Ai\Listeners\RecordToolCall;
use App\Ai\Listeners\RollBackOnGuardrailBreach;
use App\Ai\Listeners\StopTrialOnGuardrailBreach;
use App\Ai\Privacy\AllowList;
use App\Ai\Privacy\Detectors\CardNumber;
use App\Ai\Privacy\Detectors\EmailAddress;
use App\Ai\Privacy\Detectors\Iban;
use App\Ai\Privacy\Detectors\PhoneNumber;
use App\Ai\Privacy\Detectors\UsSocialSecurityNumber;
use App\Ai\Privacy\Ner;
use App\Ai\Privacy\NullNer;
use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Privacy\PresidioClient;
use App\Ai\Privacy\RedisVaultStore;
use App\Ai\Privacy\VaultFactory;
use App\Ai\Privacy\VaultStore;
use App\Ai\Quantization\LadderBackend;
use App\Ai\Quantization\OllamaLadderBackend;
use App\Ai\Sandbox\SandboxRunner;
use App\Ai\Tools\AccountStatus\AccountStatus;
use App\Ai\Tools\CalculateProration\CalculateProration;
use App\Ai\Tools\ChangeBillingEmail\ChangeBillingEmail;
use App\Ai\Tools\CheckPaymentStatus\CheckPaymentStatus;
use App\Ai\Tools\Credentials\ToolCredentials;
use App\Ai\Tools\IssueCredit\IssueCredit;
use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\LookUpPlanChange\LookUpPlanChange;
use App\Ai\Tools\Mcp\LaravelMcpClient;
use App\Ai\Tools\Mcp\McpToolSource;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ResendInvoices\ResendInvoices;
use App\Ai\Tools\RetryPayment\RetryPayment;
use App\Ai\Tools\SearchHelpCenter\SearchHelpCenter;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Training\HubUploader;
use App\Ai\Training\HuggingFaceHub;
use App\Evals\MlflowReporter;
use App\Evals\Sweep\SweepReport;
use App\Livewire\Pulse\AiBudgets;
use App\Livewire\Pulse\AiHealth;
use App\Livewire\Pulse\AiSpend;
use App\Models\PendingToolCall;
use App\Models\User;
use App\Policies\PendingActionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerPrivacy();

        $this->app->singleton(FakeBatchDriver::class);

        $this->app->bind(MlflowReporter::class, fn () => MlflowReporter::fromConfig());

        $this->app->bind(SweepReport::class, fn () => SweepReport::fromConfig());

        $this->app->singleton(Counters::class, fn () => config('ai.budgets.store') === 'array'
            ? new ArrayCounters
            : RedisCounters::fromConfig());

        $this->app->bind(HubUploader::class, HuggingFaceHub::class);

        $this->app->bind(LadderBackend::class, OllamaLadderBackend::class);
        $this->app->bind(SandboxRunner::class, fn () => SandboxRunner::fromConfig());
        $this->app->bind(ToolCredentials::class, fn () => ToolCredentials::fromConfig());

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = (new ToolRegistry)
                ->add(new LookupInvoice)
                ->add(new OpenTicket)
                ->add(new AccountStatus)
                ->add(new LookUpPlanChange)
                ->add($app->make(ListInvoices::class))
                ->add(new CheckPaymentStatus)
                ->add(new CalculateProration)
                ->add(new ResendInvoices)
                ->add(new RetryPayment)
                ->add(new ChangeBillingEmail)
                ->add(new IssueCredit)
                ->addMcpOnly($app->make(SearchHelpCenter::class));

            foreach (config('ai.mcp_servers', []) as $server) {
                $registry->addSource(new McpToolSource(                   // empty unless config('ai.mcp_servers') has entries
                    LaravelMcpClient::fromConfig($server),
                    toolKinds: $server['tool_kinds'] ?? [],
                ));
            }

            return $registry;
        });
    }

    private function registerPrivacy(): void
    {
        $this->app->bind(Ner::class, fn () => filled(config('ai.privacy.presidio_url'))
            ? PresidioClient::fromConfig()
            : new NullNer);

        $this->app->bind(PersonalDataDetector::class, fn ($app) => new PersonalDataDetector(
            detectors: [new CardNumber, new UsSocialSecurityNumber, new Iban, EmailAddress::fromConfig(), PhoneNumber::fromConfig()],
            allowList: AllowList::fromConfig(),
            ner: $app->make(Ner::class),
        ));

        $this->app->singleton(VaultStore::class, fn () => new RedisVaultStore((string) config('ai.privacy.vault.connection', 'default')));

        $this->app->singleton(VaultFactory::class, fn ($app) => VaultFactory::fromConfig($app->make(VaultStore::class)));
    }

    // Chapter 35, Topic 6: one named limiter per provider ('ai:frontier'), in requests per minute, set below the provider's own limit. A provider with no limit configured is not limited.
    private function registerProviderLimiters(): void
    {
        foreach (array_keys(config('ai.providers', [])) as $provider) {
            RateLimiter::for("ai:{$provider}", function () use ($provider) {
                $perMinute = (int) config("ai.limits.{$provider}.requests_per_minute", 0);

                return $perMinute > 0
                    ? Limit::perMinute($perMinute)
                    : Limit::none();
            });
        }
    }

    public function boot(): void
    {
        Event::listen(ToolCalled::class, RecordToolCall::class);

        $this->registerProviderLimiters();

        Event::listen(EmergencyKillSwitchActivated::class, AnnounceKillSwitch::class);
        Event::listen(BudgetThresholdReached::class, NotifyBudgetThreshold::class);
        Event::listen(GuardrailBreached::class, StopTrialOnGuardrailBreach::class);
        Event::listen(GuardrailBreached::class, RollBackOnGuardrailBreach::class);
        Event::listen(FineTuneSucceeded::class, NotifyFineTuneFinished::class);
        Event::listen(FineTuneEnded::class, NotifyFineTuneFinished::class);

        Gate::policy(PendingToolCall::class, PendingActionPolicy::class);

        Gate::define('viewPulse', fn (?User $user = null) => $user?->role === 'support');       // Pulse shows tenants' busiest users and slow queries: Ledgerly staff only

        Livewire::component('ai-spend', AiSpend::class);
        Livewire::component('ai-health', AiHealth::class);
        Livewire::component('ai-budgets', AiBudgets::class);

        Gate::define('use-tool', function (User $user, string $tool): bool {
            if ($user->customer_id === null) {
                return false;                    // Ledgerly staff have no customer to be scoped to
            }

            if ($user->role !== 'viewer') {
                return true;
            }

            $registry = app(ToolRegistry::class);

            $registered = $registry->get($tool) ?? $registry->native()->get($tool);

            return $registered?->kind() === ToolKind::Read;      // viewers read, they do not write
        });
    }
}
