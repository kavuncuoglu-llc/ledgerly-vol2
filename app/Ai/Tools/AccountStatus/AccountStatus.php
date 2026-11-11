<?php

namespace App\Ai\Tools\AccountStatus;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\Customer;
use App\Models\User;

final class AccountStatus implements Tool
{
    use ToolDefaults;

    public static function exampleArgs(): array
    {
        return [];
    }

    public function name(): string
    {
        return 'account_status';
    }

    public function arguments(): string
    {
        return AccountStatusArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function description(): string
    {
        return 'Read the current customer\'s own account: plan, status (active, trial or read_only) and number of seats. Use for questions about access, limits or why something is unavailable on the account. Takes no arguments that choose an account.';
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $customer = Customer::query()->select(['id', 'plan', 'status', 'seats'])->findOrFail($ctx->customerId());                      // the model never supplies the account

        return ToolResult::ok([
            'plan' => $customer->plan,
            'status' => $customer->status,
            'seats' => $customer->seats,
            'team_members' => User::query()->where('customer_id', $customer->id)->count(),
        ]);
    }
}
