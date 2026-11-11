<?php

namespace App\Ai\Budgets;

use RuntimeException;

// Chapter 36, Topic 5: not an error to show a customer. Features catch it and apply their own degrade ladder from $rung down.
final class BudgetExhausted extends RuntimeException
{
    public function __construct(public readonly Budget $budget, public readonly string $rung, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "The budget [{$budget->key}] is spent; fall to rung [{$rung}].");
    }

    public static function killSwitch(string $scope, string $feature): self
    {
        $budget = new Budget("{ai-budget}:kill:{$scope}", 'kill', "kill switch ({$scope})", 0, 0, $scope);

        return new self($budget, Ladder::BOTTOM, "The kill switch [{$scope}] is on for [{$feature}]: spend is stopped, show the bottom rung.");
    }

    public function isKillSwitch(): bool
    {
        return $this->budget->kind === 'kill';
    }

    public function resetsInSeconds(): int
    {
        return max(60, $this->budget->resetsInSeconds);
    }
}
