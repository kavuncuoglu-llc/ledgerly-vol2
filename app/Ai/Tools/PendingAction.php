<?php

namespace App\Ai\Tools;

use App\Models\PendingToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Chapter 33, Topic 6: a confirmation bound to exactly these arguments, this customer and this approver. Nothing runs until a person with the token says yes.
final class PendingAction
{
    public const TTL_MINUTES = 15;

    public static function request(Tool $tool, array $args, ToolContext $ctx): PendingToolCall
    {
        return PendingToolCall::create([
            'user_id' => $ctx->user->id,
            'customer_id' => $ctx->customerId(),
            'tenant_id' => $ctx->tenantId(),
            'conversation_id' => $ctx->conversationId,
            'tool' => $tool->name(),
            'tool_call_id' => $ctx->toolCallId ?? '',
            'arguments' => $args,
            'args_hash' => self::hashOf($args),
            'approver' => $tool->approver()->value,                                  // customer or agent
            'summary' => $tool->describe($args, $ctx),                               // written by code, not the model
            'source' => $ctx->source,
            'token_name' => $ctx->tokenName,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    public static function hashOf(array $args): string
    {
        return hash('sha256', json_encode($args));
    }

    // Derived, not stored: the token is shown to the approver's page only, and the model's context never holds it.
    public static function tokenFor(PendingToolCall $pending): string
    {
        return hash_hmac('sha256', "{$pending->id}|{$pending->args_hash}|{$pending->customer_id}", (string) config('app.key'));
    }

    // 410 gone (used or expired), 403 not yours or a wrong token, 409 the stored arguments are not the ones the token was made for.
    public static function authorise(PendingToolCall $pending, ?string $token, User $approver): void
    {
        abort_unless($pending->used_at === null && $pending->isPending() && $pending->expires_at?->isFuture(), 410, 'This request has expired or has already been answered.');

        abort_unless(Gate::forUser($approver)->allows('approve', $pending), 403);

        abort_unless(hash_equals(self::tokenFor($pending), (string) $token), 403);

        abort_unless(self::hashOf($pending->arguments) === $pending->args_hash, 409);
    }

    public static function claim(PendingToolCall $pending, User $approver, bool $approved): bool
    {
        $claimed = PendingToolCall::query()
            ->whereKey($pending->id)
            ->where('status', 'pending')
            ->whereNull('used_at')
            ->update([
                'status' => $approved ? 'approved' : 'declined',
                'used_at' => now(),                                                  // one decision, once, even if the button is pressed twice
                'approved_by' => $approver->id,
            ]);

        return $claimed === 1;
    }
}
