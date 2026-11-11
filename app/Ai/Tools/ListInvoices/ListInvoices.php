<?php

namespace App\Ai\Tools\ListInvoices;

use App\Ai\Encoding\DataEncoder;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Support\Ledgerly;

final class ListInvoices implements Tool
{
    use ToolDefaults;

    private const FETCH_LIMIT = 200;

    public function __construct(private DataEncoder $encoder) {}

    public function name(): string
    {
        return 'list_invoices';
    }

    public function arguments(): string
    {
        return ListInvoicesArgs::class;
    }

    public function description(): string
    {
        return 'List the current customer\'s invoices from the last few months, optionally only one status. Returns a table with number, status, due date, total, late fee and days overdue for each. Use for questions about several invoices at once, such as which are overdue. Do not use for one specific invoice; use lookup_invoice.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $rows = $ctx->user->customer->invoices()
            ->when($args->status, fn ($q) => $q->where('status', $args->status))
            ->where('issued_at', '>=', Ledgerly::today()->subMonths($args->months ?? 6))
            ->latest('issued_at')
            ->limit(self::FETCH_LIMIT)
            ->get();

        return ToolResult::text($this->encoder->encode($rows, new InvoiceSummaryShape, key: 'invoices', feature: 'support.reply'));
    }
}
