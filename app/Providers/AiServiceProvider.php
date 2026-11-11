<?php

namespace App\Providers;

use App\Ai\Events\ToolCalled;
use App\Ai\Listeners\RecordToolCall;
use App\Ai\Sandbox\SandboxRunner;
use App\Ai\Tools\AccountStatus\AccountStatus;
use App\Ai\Tools\ChangeBillingEmail\ChangeBillingEmail;
use App\Ai\Tools\CheckPaymentStatus\CheckPaymentStatus;
use App\Ai\Tools\Credentials\ToolCredentials;
use App\Ai\Tools\IssueCredit\IssueCredit;
use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\Mcp\LaravelMcpClient;
use App\Ai\Tools\Mcp\McpToolSource;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ResendInvoices\ResendInvoices;
use App\Ai\Tools\RetryPayment\RetryPayment;
use App\Ai\Tools\SearchHelpCenter\SearchHelpCenter;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Livewire\Pulse\AiHealth;
use App\Livewire\Pulse\AiSpend;
use App\Models\PendingToolCall;
use App\Models\User;
use App\Policies\PendingActionPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SandboxRunner::class, fn () => SandboxRunner::fromConfig());
        $this->app->bind(ToolCredentials::class, fn () => ToolCredentials::fromConfig());

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = (new ToolRegistry)
                ->add(new LookupInvoice)
                ->add(new OpenTicket)
                ->add(new AccountStatus)
                ->add($app->make(ListInvoices::class))
                ->add(new CheckPaymentStatus)
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

    public function boot(): void
    {
        Event::listen(ToolCalled::class, RecordToolCall::class);

        Gate::policy(PendingToolCall::class, PendingActionPolicy::class);

        Gate::define('viewPulse', fn (?User $user = null) => $user?->role === 'support');       // Pulse shows tenants' busiest users and slow queries: Ledgerly staff only

        Livewire::component('ai-spend', AiSpend::class);
        Livewire::component('ai-health', AiHealth::class);

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
