<?php

namespace App\Ai\Load;

final readonly class SimulationReport
{
    /** @param list<int> $ttftMs sorted */
    public function __construct(
        public int $users,
        public int $turns,
        public float $seconds,
        public int $answered,
        public int $failed,
        public int $unfinished,
        public array $ttftMs,
        public int $completionTokens,
    ) {}

    public function percentile(int $p): ?int
    {
        if ($this->ttftMs === []) {
            return null;
        }

        $rank = (int) ceil(count($this->ttftMs) * $p / 100) - 1;

        return $this->ttftMs[max(0, min($rank, count($this->ttftMs) - 1))];
    }

    public function tokensPerSecond(): float
    {
        return $this->seconds > 0
            ? $this->completionTokens / $this->seconds
            : 0.0;
    }

    public function line(): string
    {
        $ttft = $this->ttftMs === []
            ? 'TTFT n/a (nothing streamed)'
            : sprintf('TTFT p50 %s ms · p99 %s ms', number_format($this->percentile(50)), number_format($this->percentile(99)));

        $failures = $this->failed + $this->unfinished;

        return sprintf('%d users × %d turns · %s · %d failure%s · aggregate %s tok/s', $this->users, $this->turns, $ttft, $failures, $failures === 1 ? '' : 's', number_format($this->tokensPerSecond()));
    }
}
