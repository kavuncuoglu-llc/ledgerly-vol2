<?php

namespace App\Mcp\Servers;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Mcp\McpToolAdapter;
use App\Mcp\Prompts\SupportPromptMcp;
use App\Mcp\Prompts\TicketClassifyPromptMcp;
use App\Mcp\Resources\CustomerInvoicesTemplate;
use App\Mcp\Resources\HelpArticleResource;
use Laravel\Mcp\Server;

final class Ledgerly extends Server
{
    protected string $name = 'Ledgerly';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read-mostly access to a Ledgerly account: customers, invoices, help center. Writes require approval in the Ledgerly app.';

    protected array $resources = [
        HelpArticleResource::class,           // help://articles/{category}/{slug}
        CustomerInvoicesTemplate::class,      // ledgerly://customers/{id}/invoices
    ];

    protected array $prompts = [
        SupportPromptMcp::class,              // the app's versioned prompts, reused
        TicketClassifyPromptMcp::class,
    ];

    protected function boot(): void
    {
        $this->tools = app(ToolRegistry::class)->native()      // the same tools the assistant uses
            ->map(fn (Tool $tool) => new McpToolAdapter($tool))   // Tool interface → package tool contract
            ->values()
            ->all();
    }
}
