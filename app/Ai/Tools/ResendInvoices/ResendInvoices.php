<?php

namespace App\Ai\Tools\ResendInvoices;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Mail\InvoiceCopy;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

// Chapter 33, Figure 33-2: everything that could widen its reach is fixed by code. The address is the billing email on file; the invoices are this customer's.
final class ResendInvoices implements Tool
{
    use ToolDefaults;

    public static function exampleArgs(): array
    {
        return ['invoice_numbers' => ['INV-4471']];
    }

    public function name(): string
    {
        return 'resend_invoices';
    }

    public function arguments(): string
    {
        return ResendInvoicesArgs::class;
    }

    public function description(): string
    {
        return 'Email copies of up to ten of the current customer\'s invoices to the billing email already on their account. There is no way to choose another address. Use when the customer asks for an invoice to be sent again.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteLowRisk;
    }

    public function perConversationLimit(): int
    {
        return 3;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $numbers = array_map(fn (string $number) => 'INV-'.preg_replace('/\D/', '', $number), $args->invoice_numbers);

        $invoices = Invoice::query()->forTools()
            ->where('customer_id', $ctx->customerId())                              // the requester's authority
            ->whereIn('number', $numbers)
            ->limit(10)
            ->get();

        $to = Customer::query()->select(['id', 'email'])->findOrFail($ctx->customerId())->email;

        foreach ($invoices as $invoice) {
            Mail::to($to)->queue(new InvoiceCopy($invoice->number, (float) $invoice->total));        // never a model-supplied address
        }

        return ToolResult::ok(['sent' => $invoices->pluck('number')->all(), 'to' => 'the billing email on file']);
    }
}
