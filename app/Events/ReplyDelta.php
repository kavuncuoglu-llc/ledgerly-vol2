<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyDelta implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $replyId, public string $text, public int $offset = 0) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("reply.{$this->replyId}");
    }

    public function broadcastAs(): string
    {
        return 'ReplyDelta';
    }

    public function broadcastWith(): array
    {
        return ['t' => $this->text, 'o' => $this->offset];
    }
}
