<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('reply.{replyId}', function (User $user, int $replyId) {
    if ($user->tenant_id === null) {
        return false;                                                              // staff accounts have no assistant conversations
    }

    return Message::query()                                                        // the tenant scope applies: another tenant's reply is simply not there
        ->whereKey($replyId)
        ->whereNotNull('question')
        ->where('tenant_id', $user->tenant_id)
        ->whereHas('conversation', fn ($query) => $query->where('user_id', $user->id))
        ->exists();
});
