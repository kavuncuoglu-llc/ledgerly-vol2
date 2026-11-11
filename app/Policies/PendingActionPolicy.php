<?php

namespace App\Policies;

use App\Models\PendingToolCall;
use App\Models\User;

final class PendingActionPolicy
{
    public function approve(User $user, PendingToolCall $pending): bool
    {
        if ($pending->approver === 'agent') {
            return $user->role === 'support';                                       // money and anything leaving the account: a person on Ledgerly's side
        }

        if ($user->id === $pending->user_id) {
            return $user->customer_id !== null;                                    // the person the assistant was talking to
        }

        if ($user->customer_id === null) {
            return false;
        }

        if ($user->customer_id !== $pending->customer_id) {
            return false;
        }

        return $user->role !== 'viewer';                                            // the customer decides about their own account
    }
}
