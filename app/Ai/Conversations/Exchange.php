<?php

namespace App\Ai\Conversations;

use App\Models\Message;
use Illuminate\Support\Collection;

final class Exchange
{
    /** @param Collection<int, Message> $messages one user turn, the assistant turn (with tool calls) and the tool results between */
    public function __construct(public readonly Collection $messages) {}

    public function tokens(): int
    {
        return (int) $this->messages->sum('tokens');
    }

    public function firstSeq(): int
    {
        return (int) $this->messages->first()->seq;
    }

    public function lastSeq(): int
    {
        return (int) $this->messages->last()->seq;
    }

    public function isComplete(): bool
    {
        $last = $this->messages->last();

        if ($last->role !== 'assistant') {
            return false;
        }

        if ($last->content === null) {
            return false;
        }

        if (! in_array($last->status ?? 'completed', ['completed', 'paused'], true)) {
            return false;                                                      // a pending, streaming or failed reply is not an answer worth replaying
        }

        return $this->unansweredToolCalls() === [];
    }

    public function toApiMessages(): array
    {
        return $this->messages->map(fn (Message $message) => $this->toApiMessage($message))->all();
    }

    private function toApiMessage(Message $message): array
    {
        $api = ['role' => $message->role, 'content' => $message->content];

        if ($message->tool_calls) {
            $api['tool_calls'] = $message->tool_calls;
        }

        if ($message->tool_call_id !== null) {
            $api['tool_call_id'] = $message->tool_call_id;
        }

        return $api;
    }

    private function unansweredToolCalls(): array
    {
        $requested = $this->messages
            ->flatMap(fn (Message $message) => collect($message->tool_calls ?? [])->pluck('id'))
            ->all();

        $answered = $this->messages
            ->where('role', 'tool')
            ->pluck('tool_call_id')
            ->all();

        return array_values(array_diff($requested, $answered));
    }
}
