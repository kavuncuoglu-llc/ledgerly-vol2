<?php

namespace App\Ai\Tools;

final class CallCounter
{
    private array $counts = [];

    public function note(string $tool): void
    {
        $this->counts[$tool] = ($this->counts[$tool] ?? 0) + 1;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function count(string $tool): int
    {
        return $this->counts[$tool] ?? 0;
    }
}
