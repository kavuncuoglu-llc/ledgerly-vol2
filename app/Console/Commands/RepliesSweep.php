<?php

namespace App\Console\Commands;

use App\Ai\Streaming\ReplyChannel;
use App\Models\Message;
use App\Models\PendingToolCall;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

class RepliesSweep extends Command
{
    protected $signature = 'replies:sweep';

    protected $description = 'Expire approvals nobody answered and fail replies whose worker died (Chapter 18)';

    public function handle(CurrentTenant $tenant): int
    {
        $expired = $this->expireApprovals();

        $failed = $tenant->run(null, fn () => $this->failStaleReplies());

        $this->info("Expired {$expired} unanswered approvals and failed {$failed} stale replies.");

        return self::SUCCESS;
    }

    private function expireApprovals(): int
    {
        return PendingToolCall::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays((int) config('ai.approvals.expire_days', 7)))
            ->update(['status' => 'expired']);                                     // approving an expired request is a 409, and the paused message says so
    }

    private function failStaleReplies(): int
    {
        $failed = 0;

        Message::query()
            ->whereNotNull('question')
            ->whereIn('status', ['pending', 'streaming'])
            ->where('updated_at', '<', now()->subMinutes((int) config('ai.replies.stale_minutes', 15)))
            ->each(function (Message $reply) use (&$failed) {
                $reply->update(['status' => 'failed', 'content' => 'Sorry, something went wrong. Our team has been notified.']);

                (new ReplyChannel($reply->id))->failed();

                $failed++;
            });

        return $failed;
    }
}
