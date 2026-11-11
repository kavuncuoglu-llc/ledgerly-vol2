<?php

namespace App\Ai\Tools\CalculateProration;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;

// Chapter 38, Topic 8: arithmetic goes to a function. It reads nothing and writes nothing, so it needs no customer and no grant; the model supplies the numbers it took from a tool result or a help article and gets back the amount and the formula it can quote.
final class CalculateProration implements Tool
{
    use ToolDefaults;

    public static function exampleArgs(): array
    {
        return ['kind' => 'late_fee', 'old_price' => null, 'new_price' => null, 'days_remaining' => null, 'days_in_period' => null, 'balance' => 1000.0, 'days_overdue' => 30];
    }

    public function name(): string
    {
        return 'calculate_proration';
    }

    public function arguments(): string
    {
        return CalculateProrationArgs::class;
    }

    public function description(): string
    {
        return 'Calculate a prorated amount exactly: the charge for upgrading a plan part-way through a period (kind plan_change), or the late fee on an overdue balance (kind late_fee). Use it instead of doing the sum yourself whenever an answer states an amount that is not already in a tool result. It only calculates; it never changes an invoice or an account.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        if ($args->kind === 'plan_change') {
            return ToolResult::ok(Proration::planChange((float) $args->old_price, (float) $args->new_price, (int) $args->days_remaining, (int) $args->days_in_period));
        }

        return ToolResult::ok(Proration::lateFee((float) $args->balance, (int) $args->days_overdue));
    }
}
