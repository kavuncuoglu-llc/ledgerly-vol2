<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ChatResponse;
use App\Models\PendingToolCall;

final readonly class AgentResult
{
    public const DONE = 'done';

    public const EXHAUSTED = 'exhausted';

    public const PAUSED = 'paused';

    /** @param PendingToolCall[] $pending */
    private function __construct(
        public string $status,
        public AgentState $state,
        public Trace $trace,
        public ?ChatResponse $response = null,
        public ?string $reason = null,
        public array $pending = [],
        public int $rounds = 0,
        public int $tokens = 0,
        public int $writes = 0,
        public float $seconds = 0.0,
        public ?int $runId = null,
    ) {}

    public static function done(AgentState $state, ChatResponse $response, Trace $trace, AgentBudget $budget): self
    {
        return new self(self::DONE, $state, $trace, $response, null, [], $budget->rounds(), $budget->tokens(), $budget->writes(), $budget->seconds());
    }

    public static function exhausted(AgentState $state, Trace $trace, AgentBudget $budget, ?ChatResponse $last = null): self
    {
        return new self(self::EXHAUSTED, $state, $trace, $last, $budget->reason(), [], $budget->rounds(), $budget->tokens(), $budget->writes(), $budget->seconds());
    }

    public static function paused(AgentState $state, ChatResponse $response, Trace $trace, AgentBudget $budget, array $pending): self
    {
        return new self(self::PAUSED, $state, $trace, $response, 'approval', $pending, $budget->rounds(), $budget->tokens(), $budget->writes(), $budget->seconds());
    }

    public function withRunId(int $runId): self
    {
        return new self($this->status, $this->state, $this->trace, $this->response, $this->reason, $this->pending, $this->rounds, $this->tokens, $this->writes, $this->seconds, $runId);
    }

    public function content(): string
    {
        return (string) $this->response?->content;
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function isExhausted(): bool
    {
        return $this->status === self::EXHAUSTED;
    }

    public function isPaused(): bool
    {
        return $this->status === self::PAUSED;
    }
}
