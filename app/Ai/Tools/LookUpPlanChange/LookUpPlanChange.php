<?php

namespace App\Ai\Tools\LookUpPlanChange;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\PlanChange;

// Chapter 49, Topic 8: the small read-only tool an agent builds against acceptance tests written first.
final class LookUpPlanChange implements Tool
{
    use ToolDefaults;

    private const HOW = [
        'self_serve' => 'by the customer, in the app',
        'sales' => 'through the sales team',
        'renewal' => 'at the renewal date',
        'billing_system' => 'by the billing system',
    ];

    public static function exampleArgs(): array
    {
        return [];
    }

    public function name(): string
    {
        return 'look_up_plan_change';
    }

    public function arguments(): string
    {
        return LookUpPlanChangeArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function description(): string
    {
        return 'Read when and how the current customer\'s own plan last changed (an upgrade, a downgrade, or the plan they signed up on). Use for questions such as when the account moved to its current plan. Takes no arguments that choose an account.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $changes = PlanChange::query()
            ->where('customer_id', $ctx->customerId())                                   // the model never supplies the account
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get(['id', 'customer_id', 'from_plan', 'to_plan', 'change_type', 'via', 'changed_at']);

        $last = $changes->first();

        if ($last === null) {
            return ToolResult::ok(['found' => false]);
        }

        return ToolResult::ok([
            'found' => true,
            'last_change' => [
                'from_plan' => $last->from_plan,
                'to_plan' => $last->to_plan,
                'type' => $last->change_type,
                'changed_at' => $last->changed_at->toDateString(),
                'how' => self::HOW[$last->via] ?? $last->via,
            ],
            'changes_on_record' => $changes->count(),
        ]);
    }
}
