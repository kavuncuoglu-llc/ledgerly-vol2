<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ToolCallStarted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $replyId, public string $tool) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("reply.{$this->replyId}");
    }

    public function broadcastAs(): string
    {
        return 'ToolCallStarted';
    }

    public function broadcastWith(): array
    {
        return ['tool' => $this->tool];
    }
}
