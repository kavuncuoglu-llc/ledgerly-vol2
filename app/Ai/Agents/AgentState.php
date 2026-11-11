<?php

namespace App\Ai\Agents;

use App\Models\AgentRun;
use Illuminate\Support\Str;

// What the loop knows beyond the message history: the goal, the facts it started with, what it has established, and the turns so far. Plain data, so it can wait in the database for an approval.
final class AgentState
{
    public function __construct(
        public readonly string $id,
        public readonly string $goal,
        public readonly array $facts = [],
        public readonly array $findings = [],
        public readonly array $messages = [],
        public readonly array $progress = [],
    ) {}

    public static function fresh(string $goal, array $facts = [], array $messages = [], ?string $id = null): self
    {
        return new self($id ?? (string) Str::uuid(), $goal, $facts, [], $messages);
    }

    public static function load(int $runId): self
    {
        return self::fromArray(AgentRun::query()->findOrFail($runId)->state);
    }

    public function withMessages(array $messages): self
    {
        return new self($this->id, $this->goal, $this->facts, $this->findings, $messages, $this->progress);
    }

    public function withMessage(array $message): self
    {
        return $this->withMessages([...$this->messages, $message]);
    }

    public function withFinding(string $key, mixed $value, bool $append = false): self
    {
        $findings = $this->findings;

        $append
            ? $findings[$key][] = $value
            : $findings[$key] = $value;

        return new self($this->id, $this->goal, $this->facts, $findings, $this->messages, $this->progress);
    }

    public function withProgress(array $progress): self
    {
        return new self($this->id, $this->goal, $this->facts, $this->findings, $this->messages, $progress);
    }

    public function findings(): array
    {
        return $this->findings;
    }

    public function hash(): string
    {
        return md5(json_encode([$this->facts, $this->findings, $this->progress], JSON_UNESCAPED_SLASHES));   // the messages are not part of it: a model that repeats itself adds messages and learns nothing
    }

    public function toArray(bool $withMessages = true): array
    {
        return [
            'id' => $this->id,
            'goal' => $this->goal,
            'facts' => $this->facts,
            'findings' => $this->findings,
            'messages' => $withMessages ? $this->messages : [],
            'progress' => $this->progress,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            goal: (string) $data['goal'],
            facts: $data['facts'] ?? [],
            findings: $data['findings'] ?? [],
            messages: $data['messages'] ?? [],
            progress: $data['progress'] ?? [],
        );
    }
}
