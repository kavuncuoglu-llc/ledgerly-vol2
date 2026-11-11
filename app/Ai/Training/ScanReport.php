<?php

namespace App\Ai\Training;

// Chapter 40, Topic 9, Figure 40-9: one line per check, and the rows each one flagged (their ids, never their text).
final readonly class ScanReport
{
    /** @param array<string, array{flagged: list<string>, line: string, detail: array}> $checks */
    public function __construct(public array $checks) {}

    public function flagged(): int
    {
        return count(array_unique(array_merge([], ...array_values(array_map(fn (array $check) => $check['flagged'], $this->checks)))));
    }

    /** @return list<string> */
    public function lines(): array
    {
        return array_values(array_map(fn (array $check) => $check['line'], $this->checks));
    }

    public function toArray(): array
    {
        return ['flagged_rows' => $this->flagged(), 'checks' => $this->checks];
    }
}
