<?php

namespace App\Ai\Streaming;

use App\Events\ReplyCompleted;
use App\Events\ReplyDelta;
use App\Events\ReplyFailed;
use App\Events\ReplyPaused;
use App\Events\ToolCallStarted;
use Illuminate\Support\Facades\Log;
use Throwable;

// Everything the browser is told about one reply. A broadcast is a display optimisation: if Reverb is down the job must carry on and finish, because the stored message is the truth and the page recovers from it.
final class ReplyChannel
{
    private bool $warned = false;

    public function __construct(private int $replyId) {}

    public function delta(string $text, int $offset): void
    {
        $this->send(fn () => ReplyDelta::dispatch($this->replyId, $text, $offset));
    }

    public function toolStarted(string $tool): void
    {
        $this->send(fn () => ToolCallStarted::dispatch($this->replyId, $tool));
    }

    public function paused(string $summary, int $pendingId, string $tool): void
    {
        $this->send(fn () => ReplyPaused::dispatch($this->replyId, $summary, $pendingId, $tool));
    }

    public function completed(array $sources, bool $citationsOk): void
    {
        $this->send(fn () => ReplyCompleted::dispatch($this->replyId, $sources, $citationsOk));
    }

    public function failed(): void
    {
        $this->send(fn () => ReplyFailed::dispatch($this->replyId));
    }

    private function send(callable $broadcast): void
    {
        try {
            $broadcast();
        } catch (Throwable $e) {
            if ($this->warned) {
                return;
            }

            $this->warned = true;

            Log::warning('Broadcast failed; the reply continues and the page falls back to the stored message.', ['reply' => $this->replyId, 'error' => $e->getMessage()]);
        }
    }
}
