<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyPaused implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $replyId, public string $summary, public int $pendingId, public string $tool = '') {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("reply.{$this->replyId}");
    }

    public function broadcastAs(): string
    {
        return 'ReplyPaused';
    }

    public function broadcastWith(): array
    {
        return ['summary' => $this->summary, 'pending_id' => $this->pendingId, 'tool' => $this->tool, 'approve_url' => route('support.approve', $this->pendingId, false), 'decline_url' => route('support.decline', $this->pendingId, false)];
    }
}
