<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\Usage;
use App\Ai\Tools\ToolKind;
use InvalidArgumentException;

final class AgentBudget
{
    private int $rounds;

    private int $tokens = 0;

    private int $writes = 0;

    private float $startedAt;

    private ?string $lastStateHash = null;

    private int $repeats = 0;

    public function __construct(private int $maxRounds, private int $maxTokens, private int $maxSeconds, private int $maxWrites, int $roundsAlreadyUsed = 0)
    {
        $this->rounds = $roundsAlreadyUsed;
        $this->startedAt = microtime(true);
    }

    public static function fromConfig(string $agent, int $roundsAlreadyUsed = 0): self
    {
        $config = config("ai.agents.{$agent}") ?? throw new InvalidArgumentException("No budget configured for agent [{$agent}] in ai.agents.");

        return new self((int) $config['max_rounds'], (int) $config['max_tokens'], (int) $config['max_seconds'], (int) $config['max_writes'], $roundsAlreadyUsed);
    }

    public static function fromArray(array $config, int $roundsAlreadyUsed = 0): self
    {
        return new self((int) $config['max_rounds'], (int) $config['max_tokens'], (int) $config['max_seconds'], (int) $config['max_writes'], $roundsAlreadyUsed);
    }

    public function allows(AgentState $state): bool
    {
        $hash = $state->hash();

        $this->repeats = $hash === $this->lastStateHash
            ? $this->repeats + 1
            : 0;

        $this->lastStateHash = $hash;

        return $this->reason() === null;
    }

    public function recordRound(Usage $usage): void
    {
        $this->rounds++;
        $this->tokens += $usage->total();
    }

    public function recordTool(ToolKind $kind): void
    {
        if ($kind->isWrite()) {
            $this->writes++;                                              // an attempt counts, even when the write turned out to be a no-op
        }
    }

    /** @return string|null 'rounds' | 'tokens' | 'time' | 'writes' | 'no_progress', or null while there is room */
    public function reason(): ?string
    {
        return match (true) {
            $this->repeats >= 2 => 'no_progress',                          // two identical states in a row: the model is stuck
            $this->writes >= $this->maxWrites => 'writes',
            $this->rounds >= $this->maxRounds => 'rounds',
            $this->tokens >= $this->maxTokens => 'tokens',
            $this->seconds() >= $this->maxSeconds => 'time',
            default => null,
        };
    }

    public function rounds(): int
    {
        return $this->rounds;
    }

    public function tokens(): int
    {
        return $this->tokens;
    }

    public function writes(): int
    {
        return $this->writes;
    }

    public function seconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function limits(): array
    {
        return ['max_rounds' => $this->maxRounds, 'max_tokens' => $this->maxTokens, 'max_seconds' => $this->maxSeconds, 'max_writes' => $this->maxWrites];
    }
}
