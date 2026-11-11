<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Tools\ToolResult;
use Illuminate\Support\Str;

// Every decision, every observation and every budget event of one run, in order: what debugging an agent looks like.
final class Trace
{
    private float $startedAt;

    private int $round = 0;

    /** @var array<int, array{t: float, type: string, round?: int, text: string}> */
    private array $entries = [];

    public function __construct(public readonly string $agent, public readonly string $stateId, ?float $startedAt = null)
    {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public function goal(string $goal): void
    {
        $this->add('goal', 'goal: '.$goal);
    }

    public function decision(ChatResponse $response): void
    {
        $this->round++;

        if ($response->toolCalls === []) {
            $this->add('decision', "r{$this->round} decision → done: ".Str::limit(trim((string) $response->content), 160), $this->round);

            return;
        }

        foreach ($response->toolCalls as $call) {
            $this->add('decision', "r{$this->round} decision → ".$this->describeCall($call), $this->round);
        }
    }

    public function observation(array $call, ToolResult $result): void
    {
        $this->add('observation', '   result: '.$this->describeResult($result), $this->round);
    }

    public function note(string $type, string $text): void
    {
        $this->add($type, $text, $this->round);
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function toArray(): array
    {
        return ['agent' => $this->agent, 'state_id' => $this->stateId, 'entries' => $this->entries];
    }

    public static function render(array $entries): string
    {
        return implode("\n", array_map(fn (array $entry) => sprintf('[%04.1fs] %s', $entry['t'], $entry['text']), $entries));
    }

    private function add(string $type, string $text, ?int $round = null): void
    {
        $this->entries[] = array_filter([
            't' => round(microtime(true) - $this->startedAt, 1),
            'type' => $type,
            'round' => $round,
            'text' => $text,
        ], fn ($value) => $value !== null);
    }

    private function describeCall(array $call): string
    {
        $name = (string) ($call['function']['name'] ?? '?');

        $arguments = $call['function']['arguments'] ?? '';

        $decoded = is_array($arguments)
            ? $arguments
            : (json_decode((string) $arguments, true) ?? []);

        $pairs = [];

        foreach ($decoded as $key => $value) {
            $pairs[] = $key.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $name.'('.implode(', ', $pairs).')';
    }

    private function describeResult(ToolResult $result): string
    {
        if ($result->isPending()) {
            return 'paused, waiting for approval';
        }

        if (! $result->ok && $result->text === null) {
            return 'error: '.$result->error;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $result->toJson())), 200);
    }
}
