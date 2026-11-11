<?php

namespace App\Ai\Listeners;

use App\Ai\Budgets\Budget;
use App\Ai\Budgets\Notifier;
use App\Ai\Budgets\Teams;
use App\Ai\Events\BudgetThresholdReached;

// Chapter 36, Topic 5: at 80% the owning team is told; for a tenant budget so is the tenant's admin, with a note of what will happen at 100%.
final class NotifyBudgetThreshold
{
    public function __construct(private Notifier $notifier) {}

    public function handle(BudgetThresholdReached $event): void
    {
        $budget = $event->budget;

        $subject = sprintf('AI budget at %d%%: %s', (int) round((float) config('ai.budgets.warn_at', 0.8) * 100), $budget->label);

        $body = sprintf('%s has spent $%s of $%s.', $budget->label, number_format($event->spentMicros / 1_000_000, 2), number_format($budget->limitMicros / 1_000_000, 2));

        $this->notifier->team(Teams::of($event->feature), $subject, $body);

        if (in_array($budget->kind, [Budget::TENANT_MONTH, Budget::TENANT_DAY], true) && $budget->subject !== null) {
            $this->notifier->tenantAdmins((int) $budget->subject, 'Your AI usage is at 80% of this period\'s allowance', $body.' At 100% Ledgerly\'s assistant answers more briefly and then with help-center search results until the period resets.');
        }
    }
}
