<?php

namespace App\Ai\Tools\LookupInvoice;

use App\Ai\Security\Untrusted;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class LookupInvoice implements Tool
{
    use ToolDefaults;

    public function name(): string
    {
        return 'lookup_invoice';
    }

    public function arguments(): string
    {
        return LookupInvoiceArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;          // registry uses this for gating
    }

    public function description(): string
    {
        return 'Look up one invoice for the current customer by invoice number or by month (YYYY-MM). Returns status, amounts, due date, and days overdue. Use whenever the customer asks about a specific invoice. Do not use for general questions about how Ledgerly works.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $query = $ctx->user->customer->invoices();              // scoped: the model never supplies the customer

        $matches = $args->invoice_number
            ? $query->whereNumber($args->invoice_number)->get()
            : $this->issuedIn($query, $args->month);

        if ($matches->isEmpty()) {
            return ToolResult::ok(['found' => false]);
        }

        $invoice = $matches->first();

        $result = ['found' => true, 'invoice' => [          // small: what the model needs, not the row
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_date' => $invoice->due_at->toDateString(),
            'days_overdue' => $invoice->daysOverdue(),
            'total' => (float) $invoice->total,
            'late_fee' => $invoice->lateFee(),
            'lines' => $this->lines($invoice),
            'payments' => $this->payments($invoice),
        ]];

        if ($matches->count() > 1) {
            $result['other_matches'] = $matches->skip(1)->pluck('number')->values()->all();
        }

        return ToolResult::ok($result);
    }

    // Chapter 32, Topic 5: the words on a line item were typed by the customer for their own client, and a payment reference by whoever paid. They travel as JSON values and each is wrapped, so a sentence in them is data the model reads, not text that reads like the tool's own.
    private function lines(Invoice $invoice): array
    {
        return $invoice->lineItems()
            ->orderBy('position')
            ->limit(10)
            ->get(['description', 'quantity', 'amount'])
            ->map(fn ($line) => [
                'description' => Untrusted::wrap((string) $line->description, 'tool:lookup_invoice'),
                'quantity' => (float) $line->quantity,
                'amount' => (float) $line->amount,
            ])
            ->all();
    }

    private function payments(Invoice $invoice): array
    {
        return $invoice->payments()
            ->orderBy('paid_at')
            ->limit(5)
            ->get(['method', 'amount', 'paid_at', 'reference'])
            ->map(fn ($payment) => [
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_at->toDateString(),
                'reference' => $payment->reference === null
                    ? null
                    : Untrusted::wrap($payment->reference, 'tool:lookup_invoice'),
            ])
            ->all();
    }

    private function issuedIn(HasMany $query, string $month): Collection
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);

        return $query
            ->where('issued_at', '>=', $first)
            ->where('issued_at', '<', $first->addMonth())
            ->orderByRaw("case when status = 'overdue' then 0 else 1 end")      // the one a customer is asking about first
            ->latest('issued_at')
            ->limit(5)
            ->get();
    }
}
