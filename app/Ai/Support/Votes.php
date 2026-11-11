<?php

namespace App\Ai\Support;

use App\Ai\Schemas\Category;

final readonly class Votes
{
    /** @param string[] $labels one classifier answer per sample */
    public function __construct(public array $labels) {}

    public function labels(): array
    {
        return $this->labels;
    }

    public function isEmpty(): bool
    {
        return $this->labels === [];
    }

    public function majority(): ?Category
    {
        if ($this->labels === []) {
            return null;
        }

        $counts = array_count_values($this->labels);

        arsort($counts);                                                        // ties keep the label that was voted first

        return Category::tryFrom((string) array_key_first($counts));
    }

    public function agreement(): float
    {
        if ($this->labels === []) {
            return 0.0;
        }

        return max(array_count_values($this->labels)) / count($this->labels);                  // 2 of 3 is 0.667: below the 0.67 line, so a 2-1 split goes to a person
    }

    /** @return string[] the distinct labels, most voted first */
    public function distinct(): array
    {
        $counts = array_count_values($this->labels);

        arsort($counts);

        return array_map('strval', array_keys($counts));
    }
}
