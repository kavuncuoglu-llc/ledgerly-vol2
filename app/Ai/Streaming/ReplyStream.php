<?php

namespace App\Ai\Streaming;

use App\Models\Message;
use Closure;

// The pump between a running reply and its viewers: each batch of text is broadcast and, at most twice a second, written to the stored message so a reconnecting page or the polling fallback sees how far the reply has got.
final class ReplyStream
{
    private string $streamed = '';

    private float $savedAt;

    public function __construct(
        private Message $reply,
        private ReplyChannel $channel,
        private float $saveEverySeconds = 0.5,
    ) {
        $this->savedAt = microtime(true);
    }

    public function start(): void
    {
        $this->reply->update(['status' => 'streaming', 'content' => null]);
    }

    public function onDelta(): Closure
    {
        return function (string $delta): void {
            $this->channel->delta($delta, mb_strlen($this->streamed));

            $this->streamed .= $delta;

            if (microtime(true) - $this->savedAt < $this->saveEverySeconds) {
                return;
            }

            $this->reply->update(['content' => $this->streamed]);

            $this->savedAt = microtime(true);
        };
    }

    public function onToolStart(): Closure
    {
        return fn (string $tool) => $this->channel->toolStarted($tool);
    }

    public function channel(): ReplyChannel
    {
        return $this->channel;
    }
}
