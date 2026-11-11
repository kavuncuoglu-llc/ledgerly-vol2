<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\Usage;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use InvalidArgumentException;

// Chapter 35, Topic 3: config can lower a limit but never remove it. A missing or misspelled entry falls back to the ceiling in code, not to "no limit".
final class AgentBudget
{
    public const MAX_ROUNDS = 8;

    public const MAX_RUN_TOKENS = 60_000;

    public const MAX_SECONDS = 90;

    public const MAX_SAME_ERROR = 3;

    private const CEILINGS = [                                           // agents that are built to run longer than the default declare it here, in code, where a config change cannot raise it
        'triage' => ['rounds' => 8, 'tokens' => 30_000, 'seconds' => 120],         // Chapter 17's own budget is two minutes: AnswerQuestion's timeout and the triage job count on it
        'reminder' => ['rounds' => 15, 'tokens' => 80_000, 'seconds' => 300],
    ];

    private int $rounds;

    private int $tokens = 0;

    private int $writes = 0;

    private float $startedAt;

    private ?string $lastStateHash = null;

    private int $repeats = 0;

    private int $sameErrors = 0;

    private ?string $lastError = null;

    private int $maxRounds;

    private int $maxTokens;

    private int $maxSeconds;

    public function __construct(?int $maxRounds, ?int $maxTokens, ?int $maxSeconds, private int $maxWrites, int $roundsAlreadyUsed = 0, string $agent = 'default')
    {
        $ceiling = self::CEILINGS[$agent] ?? ['rounds' => self::MAX_ROUNDS, 'tokens' => self::MAX_RUN_TOKENS, 'seconds' => self::MAX_SECONDS];

        $this->maxRounds = self::lowered($maxRounds, $ceiling['rounds']);
        $this->maxTokens = self::lowered($maxTokens, $ceiling['tokens']);
        $this->maxSeconds = self::lowered($maxSeconds, $ceiling['seconds']);
        $this->rounds = $roundsAlreadyUsed;
        $this->startedAt = microtime(true);
    }

    public static function fromConfig(string $agent, int $roundsAlreadyUsed = 0): self
    {
        $config = config("ai.agents.{$agent}") ?? throw new InvalidArgumentException("No budget configured for agent [{$agent}] in ai.agents.");

        return self::fromArray($config, $roundsAlreadyUsed, $agent);
    }

    public static function fromArray(array $config, int $roundsAlreadyUsed = 0, string $agent = 'default'): self
    {
        return new self(self::asInt($config['max_rounds'] ?? null), self::asInt($config['max_tokens'] ?? null), self::asInt($config['max_seconds'] ?? null), (int) ($config['max_writes'] ?? 0), $roundsAlreadyUsed, $agent);
    }

    private static function asInt(mixed $value): ?int
    {
        return $value === null
            ? null
            : (int) $value;
    }

    private static function lowered(?int $configured, int $ceiling): int
    {
        return $configured === null
            ? $ceiling                                                   // a lost or misspelled key is the ceiling, never "no limit"
            : min($configured, $ceiling);
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

    // Three identical tool errors in a row end the run: a model that retries a failing tool is not going to be talked out of it by the fourth try.
    public function recordOutcome(array $call, ToolResult $result): void
    {
        if ($result->ok) {
            $this->sameErrors = 0;
            $this->lastError = null;

            return;
        }

        $signature = ($call['function']['name'] ?? '').'|'.(string) $result->error;

        $this->sameErrors = $signature === $this->lastError
            ? $this->sameErrors + 1
            : 1;

        $this->lastError = $signature;
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
            $this->repeats >= 2 => 'no_progress',
            $this->sameErrors >= self::MAX_SAME_ERROR => 'same_error',                          // two identical states in a row: the model is stuck
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
