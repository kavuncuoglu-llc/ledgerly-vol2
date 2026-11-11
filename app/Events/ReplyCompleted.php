<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplyCompleted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $replyId, public array $sources = [], public bool $citationsOk = true) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("reply.{$this->replyId}");
    }

    public function broadcastAs(): string
    {
        return 'ReplyCompleted';
    }

    public function broadcastWith(): array
    {
        return ['sources' => $this->sources, 'citations_ok' => $this->citationsOk];
    }
}
