<?php

namespace App\Ai\Events;

use App\Ai\Budgets\Budget;
use Illuminate\Foundation\Events\Dispatchable;

// Chapter 36, Topic 5: at 80% nothing changes for customers; the owning team is told, and for a tenant budget so is the tenant's admin, with what will happen at 100%.
final class BudgetThresholdReached
{
    use Dispatchable;

    public function __construct(public readonly Budget $budget, public readonly string $feature, public readonly int $spentMicros) {}
}
