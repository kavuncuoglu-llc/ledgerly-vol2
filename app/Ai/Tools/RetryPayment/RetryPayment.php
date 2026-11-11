<?php

namespace App\Ai\Tools\RetryPayment;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class RetryPayment implements Tool
{
    use ToolDefaults;

    public static function exampleArgs(): array
    {
        return ['invoice_number' => 'INV-4471'];
    }

    public function name(): string
    {
        return 'retry_payment';
    }

    public function arguments(): string
    {
        return RetryPaymentArgs::class;
    }

    public function description(): string
    {
        return 'Ask for the payment on one overdue invoice of the current customer to be collected again. This moves money, so it waits for the customer to confirm on an approval card; only call it when the customer has asked for a retry. To see what has been paid, use check_payment_status.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteHighRisk;
    }

    public function perConversationLimit(): int
    {
        return 2;
    }

    public function describeIntent(array $args, ToolContext $ctx): string
    {
        $invoice = Invoice::query()->forTools()->where('customer_id', $ctx->customerId())->whereNumber($args['invoice_number'])->first();

        if ($invoice === null) {
            return 'Retry the payment on an invoice that was not found on this account';
        }

        $name = Customer::query()->select(['id', 'name'])->find($ctx->customerId())?->name;

        return sprintf('Retry the payment on invoice %s (total $%s) for %s. The card or bank account on file is charged again.', $invoice->number, number_format((float) $invoice->total, 2), $name);
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoice = Invoice::query()->forTools()->where('customer_id', $ctx->customerId())->whereNumber($args->invoice_number)->first();   // current state: it may have been paid since the request

        if ($invoice === null) {
            return ToolResult::error('invoice not found');
        }

        if ($invoice->status->value === 'paid') {
            return ToolResult::error('invoice already paid');
        }

        $id = DB::table('payment_retries')->insertGetId([
            'invoice_id' => $invoice->id,
            'customer_id' => $ctx->customerId(),
            'requested_by' => $ctx->user->id,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ToolResult::ok(['queued' => true, 'invoice' => $invoice->number, 'retry' => $id]);
    }
}
