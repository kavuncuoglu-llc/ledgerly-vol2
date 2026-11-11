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
        public readonly ?int $approvedBy = null,
        private readonly CallCounter $counter = new CallCounter,
    ) {}

    public function customerId(): int
    {
        return (int) $this->user->customer_id;                             // from the session's user; no tool takes it from the model
    }

    public function tenantId(): ?int
    {
        return $this->user->tenant_id;
    }

    public function toolCallsInConversation(): int
    {
        if ($this->conversationId === null) {
            return $this->counter->total();
        }

        return ToolCall::query()
            ->where('user_id', $this->user->id)
            ->where('conversation_id', $this->conversationId)
            ->whereIn('decision', ['allowed', 'approved', 'pending_approval'])
            ->count();
    }

    public function withToolCallId(string $toolCallId): self
    {
        return new self($this->user, $this->conversationId, $this->source, $this->tokenName, $toolCallId, $this->triageRunId, $this->approvedBy, $this->counter);
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
