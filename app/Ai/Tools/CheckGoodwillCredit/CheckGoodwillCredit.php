<?php

namespace App\Ai\Tools\CheckGoodwillCredit;

use App\Ai\Policy\GoodwillCreditPolicy;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Invoice;

// Chapter 51, Topic 3: the policy as a tool. It reads and decides; it never grants. The assistant says what it returned, in its own words, and no more.
final class CheckGoodwillCredit implements Tool
{
    use ToolDefaults;

    public function __construct(private GoodwillCreditPolicy $policy = new GoodwillCreditPolicy) {}

    public static function exampleArgs(): array
    {
        return ['invoice_number' => 'INV-4471'];
    }

    public function name(): string
    {
        return 'check_goodwill_credit';
    }

    public function arguments(): string
    {
        return CheckGoodwillCreditArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function description(): string
    {
        return 'Ask the credit policy whether the current customer can request a goodwill credit on one of their invoices, and for how much at most. The answer is final: explain it, never promise a credit, and never offer one the policy refuses.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $invoice = Invoice::query()->forTools()->where('customer_id', $ctx->customerId())->whereNumber($args->invoice_number)->first();

        return ToolResult::ok($this->policy->decide($invoice, $ctx->customerId())->toArray());
    }
}
