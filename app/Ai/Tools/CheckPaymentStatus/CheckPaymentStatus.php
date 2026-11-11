<?php

namespace App\Ai\Tools\CheckPaymentStatus;

use App\Ai\Security\Untrusted;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Invoice;

// Chapter 33, Topic 3: this tool only reads. The retry it used to do on a stuck payment is retry_payment, a high-risk tool of its own.
final class CheckPaymentStatus implements Tool
{
    use ToolDefaults;

    public static function exampleArgs(): array
    {
        return ['invoice_number' => 'INV-4471'];
    }

    public function name(): string
    {
        return 'check_payment_status';
    }

    public function arguments(): string
    {
        return CheckPaymentStatusArgs::class;
    }

    public function description(): string
    {
        return 'Check what has been paid on one of the current customer\'s invoices: paid, partly paid or unpaid, the balance still due and the payments received. Read only: it never retries or collects a payment. Use when the customer says they paid, or asks why an invoice still shows unpaid.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoice = Invoice::query()->forTools()->where('customer_id', $ctx->customerId())->whereNumber($args->invoice_number)->first();

        if ($invoice === null) {
            return ToolResult::ok(['found' => false]);
        }

        $payments = $invoice->payments()->orderBy('paid_at')->get(['method', 'amount', 'paid_at', 'reference']);

        $paid = round((float) $payments->sum('amount'), 2);

        $total = (float) $invoice->total;

        return ToolResult::ok([
            'found' => true,
            'invoice' => $invoice->number,
            'state' => match (true) {
                $paid >= $total => 'paid',
                $paid > 0 => 'partly_paid',
                default => 'unpaid',
            },
            'total' => $total,
            'paid' => $paid,
            'balance_due' => round(max(0, $total - $paid), 2),
            'payments' => $payments->map(fn ($payment) => [
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_at->toDateString(),
                'reference' => $payment->reference === null ? null : Untrusted::wrap($payment->reference, 'tool:check_payment_status'),
            ])->all(),
        ]);
    }
}
