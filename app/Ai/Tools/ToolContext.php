<?php

namespace App\Ai\Tools;

use App\Models\ToolCall;
use App\Models\User;

final class ToolContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?int $conversationId = null,
        public readonly string $source = 'chat',
        public readonly ?string $tokenName = null,
        public readonly ?string $toolCallId = null,
        public readonly ?int $triageRunId = null,
        private readonly CallCounter $counter = new CallCounter,
    ) {}

    public function withToolCallId(string $toolCallId): self
    {
        return new self($this->user, $this->conversationId, $this->source, $this->tokenName, $toolCallId, $this->triageRunId, $this->counter);
    }

    public function noteCall(string $tool): void
    {
        $this->counter->note($tool);
    }

    public function toolCallsThisConversation(string $tool): int
    {
        if ($this->conversationId === null) {
            return $this->counter->count($tool);
        }

        return ToolCall::query()
            ->where('user_id', $this->user->id)
            ->where('conversation_id', $this->conversationId)
            ->where('tool', $tool)
            ->whereIn('decision', ['allowed', 'approved'])
            ->count();
    }
}
