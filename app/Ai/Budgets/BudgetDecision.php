<?php

namespace App\Ai\Budgets;

final readonly class BudgetDecision
{
    /** @param  Budget[]  $reserved  the budgets the estimate was reserved on, to be settled or released */
    private function __construct(
        public bool $allowed,
        public int $estimate = 0,
        public array $reserved = [],
        public ?Budget $budget = null,
        public ?string $rung = null,
    ) {}

    public static function allow(int $estimate, array $reserved = []): self
    {
        return new self(true, $estimate, $reserved);
    }

    public static function degrade(Budget $exhausted, string $rung): self
    {
        return new self(false, 0, [], $exhausted, $rung);
    }
}
