<?php

namespace App\Ai\Ledger;

use App\Ai\Budgets\Budget;
use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Models\AiLedgerEntry;

// Chapter 36, Topic 4: the counters answer "is there room right now" and the ledger answers everything about the past; when they disagree the ledger wins. Rebuilds the counters of the periods that are running now from the ledger.
// Run at a quiet hour (the nightly ai:reconcile): a reservation that is in flight while it runs is forgotten until the call settles.
final class CounterRebuilder
{
    public function __construct(private Counters $counters, private BudgetGuard $guard) {}

    /** @return int the number of counters set */
    public function rebuild(): int
    {
        $set = 0;

        $seen = [];

        $recent = AiLedgerEntry::query()->where('occurred_at', '>=', now()->subDays(32))->select('tenant_id', 'feature')->distinct()->get();

        foreach ($recent as $row) {
            $anonymous = in_array($row->feature, config('ai.budgets.anonymous_features', []), true);

            foreach ($this->guard->budgetsForScope($row->tenant_id, $row->feature, $anonymous) as $budget) {
                if (isset($seen[$budget->key])) {
                    continue;
                }

                $seen[$budget->key] = true;

                $this->counters->set($budget->key, (string) $this->spent($budget), $budget->ttlSeconds);

                $set++;
            }
        }

        return $set;
    }

    public function spent(Budget $budget): int
    {
        $query = AiLedgerEntry::query()->where('occurred_at', '>=', $budget->startsAt);

        match ($budget->kind) {
            Budget::TENANT_MONTH, Budget::TENANT_DAY => $query->where('tenant_id', (int) $budget->subject),
            Budget::FEATURE_DAY => $query->where('feature', $budget->subject),
            Budget::ANONYMOUS_DAY => $query->whereNull('tenant_id')->whereIn('feature', config('ai.budgets.anonymous_features', [])),
            default => null,
        };

        return (int) $query->sum('cost_micro_usd');
    }
}
