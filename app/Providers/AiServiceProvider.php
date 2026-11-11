<?php

namespace App\Providers;

use App\Ai\Events\ToolCalled;
use App\Ai\Listeners\RecordToolCall;
use App\Ai\Tools\AccountStatus\AccountStatus;
use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\Mcp\LaravelMcpClient;
use App\Ai\Tools\Mcp\McpToolSource;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\SearchHelpCenter\SearchHelpCenter;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = (new ToolRegistry)
                ->add(new LookupInvoice)
                ->add(new OpenTicket)
                ->add(new AccountStatus)
                ->add($app->make(ListInvoices::class))
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
