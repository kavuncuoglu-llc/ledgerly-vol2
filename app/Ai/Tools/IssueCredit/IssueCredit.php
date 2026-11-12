<?php

namespace App\Ai\Tools\IssueCredit;

use App\Ai\Policy\GoodwillCreditPolicy;
use App\Ai\Tools\Approver;
use App\Ai\Tools\RefusesBeforeApproval;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class IssueCredit implements RefusesBeforeApproval, Tool
{
    use ToolDefaults;

    public function __construct(private GoodwillCreditPolicy $policy = new GoodwillCreditPolicy) {}

    public static function exampleArgs(): array
    {
        return ['invoice_number' => 'INV-4471', 'amount_cents' => 1800, 'reason' => 'Late fee charged while a payment was in transit'];
    }

    public function name(): string
    {
        return 'issue_credit';
    }

    public function arguments(): string
    {
        return IssueCreditArgs::class;
    }

    public function description(): string
    {
        return 'Request a credit on one of the current customer\'s invoices, for example a late fee they dispute. Money leaves the business, so a support agent decides in a queue; the customer is told it is waiting. Never promise the customer the credit will be granted.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteHighRisk;
    }

    public function approver(): Approver
    {
        return Approver::Agent;
    }

    public function perConversationLimit(): int
    {
        return 1;
    }

    public function describeIntent(array $args, ToolContext $ctx): string
    {
        $name = Customer::query()->select(['id', 'name'])->find($ctx->customerId())?->name;

        return sprintf('Issue a $%s credit to invoice %s for %s. Reason given: %s', number_format($args['amount_cents'] / 100, 2), $args['invoice_number'], $name, $args['reason']);
    }

    public function refusal(array $args, ToolContext $ctx): ?string
    {
        $invoice = Invoice::query()->forTools()->where('customer_id', $ctx->customerId())->whereNumber((string) ($args['invoice_number'] ?? ''))->first();

        $decision = $this->policy->decide($invoice, $ctx->customerId());

        if ($decision->allows((int) ($args['amount_cents'] ?? 0))) {
            return null;
        }

        return $decision->eligible
            ? "The goodwill credit policy allows at most {$decision->maxCents} cents on this invoice. Explain the limit; do not offer more."
            : 'The goodwill credit policy does not allow a credit here: '.$decision->explanation;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoice = Invoice::query()->forTools()->where('customer_id', $ctx->customerId())->whereNumber($args->invoice_number)->first();

        if ($invoice === null) {
            return ToolResult::error('invoice not found');
        }

        if ($args->amount_cents > (int) round((float) $invoice->total * 100)) {
            return ToolResult::error('credit larger than the invoice');
        }

        if (! $this->policy->decide($invoice, $ctx->customerId())->allows($args->amount_cents)) {
            return ToolResult::error('not allowed by the goodwill credit policy');       // the same rule again, at the moment of writing: an approval does not widen it
        }

        $id = DB::table('invoice_credits')->insertGetId([
            'invoice_id' => $invoice->id,
            'customer_id' => $ctx->customerId(),
            'amount_cents' => $args->amount_cents,
            'reason' => $args->reason,
            'approved_by' => $ctx->approvedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ToolResult::ok(['credited' => true, 'invoice' => $invoice->number, 'credit' => $id]);
    }
}
