<?php

namespace App\Ai\Quantization;

final readonly class LadderResult
{
    /** @param array<string, array{bytes: int, tuned: array, base: ?array}> $levels */
    public function __construct(public array $levels, public ?string $selected, public string $reason) {}

    public function advantage(string $level): ?float
    {
        $row = $this->levels[$level] ?? null;

        if ($row === null || $row['base'] === null) {
            return null;
        }

        return round(($row['tuned']['required_facts'] - $row['base']['required_facts']) * 100, 1);
    }

    public function toArray(): array
    {
        return ['levels' => $this->levels, 'selected' => $this->selected, 'reason' => $this->reason];
    }
}
