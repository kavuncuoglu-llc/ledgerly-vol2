<?php

namespace App\Ai\Budgets;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Events\BudgetThresholdReached;
use App\Ai\Ledger\Costing;
use App\Ai\Ledger\LedgerEntry;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

// Chapter 36, Topic 5: every budget the call falls under must have room, and the call's estimated cost is reserved on all of them at once or on none. After the call the reservation is settled to the real cost;
// when the call fails it is given back. Nothing here touches the database: the counters are in Redis.
final class BudgetGuard
{
    public function __construct(
        private Counters $counters,
        private Costing $costing,
        private TokenCounter $tokens,
        private TenantProfile $tenants,
        private Ladder $ladder,
        private SpendBreaker $breaker,
        private KillSwitch $kill,
    ) {}

    public function decide(ChatRequest $request, string $provider, string $model): BudgetDecision
    {
        $estimate = $this->estimateMicroUsd($request, $provider, $model);

        if ($estimate <= 0) {
            return BudgetDecision::allow(0);                                  // your own hardware at no rate: nothing to reserve, and it is what a degraded feature falls to
        }

        $tripped = $this->breaker->blocks($request->feature, $this->tenantId());

        if ($tripped !== null) {
            return BudgetDecision::degrade($tripped, $this->ladder->cheapest($request->feature));
        }

        $budgets = $this->budgetsFor($request->feature);

        $full = $this->counters->reserveAll(collect($budgets)->mapWithKeys(fn (Budget $budget) => [$budget->key => $budget->limits()])->all(), $estimate);

        if ($full !== null && $budgets[$full]->kind === Budget::APP_HOUR) {
            $this->kill->activate('global', 'the app\'s hourly ceiling was hit ('.$budgets[$full]->label.')');       // far above normal: something is going badly wrong, and it never resets itself

            return BudgetDecision::degrade($budgets[$full], Ladder::BOTTOM);
        }

        if ($full !== null) {
            return BudgetDecision::degrade($budgets[$full], $this->ladder->next($request->feature));
        }

        $this->warnWhereCrossed($budgets, $request->feature);

        return BudgetDecision::allow($estimate, $budgets);
    }

    public function settle(BudgetDecision $decision, int $actualMicros): void
    {
        foreach ($decision->reserved as $budget) {
            $this->counters->adjust($budget->key, $actualMicros - $decision->estimate, $budget->ttlSeconds);
        }
    }

    public function release(BudgetDecision $decision): void
    {
        $this->settle($decision, 0);                                           // a failed call gives its whole reservation back
    }

    // Input tokens (counted from what will be sent) plus max_tokens at the output price: the most the call can cost.
    public function estimateMicroUsd(ChatRequest $request, string $provider, string $model): int
    {
        $input = $this->tokens->count(json_encode([$request->messages, $request->tools], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $max = (int) ($request->options['max_tokens'] ?? config('ai.budgets.default_max_tokens', 1024));

        return $this->costing->estimate($provider, $model, $input, $max);
    }

    public function estimateTokens(ChatRequest $request): int
    {
        $input = $this->tokens->count(json_encode([$request->messages, $request->tools], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return $input + (int) ($request->options['max_tokens'] ?? config('ai.budgets.default_max_tokens', 1024));
    }

    /** @return Budget[] tenant month, tenant day, feature day, anonymous day, app hour: the ones this call falls under */
    public function budgetsFor(string $feature): array
    {
        return $this->budgetsForScope($this->tenantId(), $feature, $this->isAnonymous($feature));
    }

    /** @return Budget[] */
    public function budgetsForScope(?int $tenantId, string $feature, bool $anonymous): array
    {
        $budgets = [];

        if ($tenantId !== null && ! $anonymous) {
            array_push($budgets, ...$this->tenantBudgets($tenantId));
        }

        $budgets[] = $this->periodBudget(Budget::FEATURE_DAY, "{ai-budget}:feature:{$feature}:day:", 'day', "{$feature} per day", $this->usd(config('ai.budgets.feature_day_usd')[$feature] ?? config('ai.budgets.feature_day_usd')['*'] ?? 10), $feature, 'UTC');

        if ($anonymous) {
            $budgets[] = $this->periodBudget(Budget::ANONYMOUS_DAY, '{ai-budget}:anonymous:day:', 'day', 'anonymous visitors per day', $this->usd(config('ai.budgets.anonymous_day_usd', 5)), null, 'UTC');
        }

        $budgets[] = $this->appHour();

        return $budgets;
    }

    public function appHour(): Budget
    {
        return $this->periodBudget(Budget::APP_HOUR, '{ai-budget}:app:hour:', 'hour', 'the whole app per hour', $this->usd(config('ai.budgets.app_hour_usd', 250)), null, 'UTC');
    }

    public function isAnonymous(string $feature): bool
    {
        if (in_array($feature, config('ai.budgets.anonymous_features', ['widget.ask']), true)) {
            return true;
        }

        return Context::get('channel') === 'anonymous';
    }

    private function tenantBudgets(int $tenantId): array
    {
        $profile = $this->tenants->of($tenantId);

        $month = $this->usd(config("ai.budgets.tenant_month_usd.{$profile['plan']}", config('ai.budgets.tenant_month_usd.default', 25)));

        $day = (int) round($month * (float) config('ai.budgets.tenant_day_share', 0.2));

        return [
            $this->periodBudget(Budget::TENANT_MONTH, "{ai-budget}:tenant:{$tenantId}:month:", 'month', "tenant {$tenantId}, month ({$profile['plan']} plan)", $month, (string) $tenantId, $profile['timezone']),
            $this->periodBudget(Budget::TENANT_DAY, "{ai-budget}:tenant:{$tenantId}:day:", 'day', "tenant {$tenantId}, day", $day, (string) $tenantId, $profile['timezone']),
        ];
    }

    // A key per period, which expires after it: the day in the tenant's own time zone, so "degrade until midnight" is the tenant's midnight.
    private function periodBudget(string $kind, string $prefix, string $period, string $label, int $limitMicros, ?string $subject, string $timezone): Budget
    {
        $now = Carbon::now($timezone);

        [$stamp, $start, $end] = match ($period) {
            'month' => [$now->format('Y-m'), $now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'hour' => [$now->format('Y-m-d\TH'), $now->copy()->startOfHour(), $now->copy()->endOfHour()],
            default => [$now->format('Y-m-d'), $now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        $left = max(1, (int) $now->diffInSeconds($end, true));

        return new Budget($prefix.$stamp, $kind, $label, $limitMicros, $left + 3600, $subject, $left, $start);
    }

    private function warnWhereCrossed(array $budgets, string $feature): void
    {
        $threshold = (float) config('ai.budgets.warn_at', 0.8);

        foreach ($budgets as $budget) {
            $spent = $this->counters->value($budget->key);

            if ($budget->limitMicros <= 0 || $spent < $threshold * $budget->limitMicros) {
                continue;
            }

            $flag = $budget->key.':warned';

            if ($this->counters->get($flag) !== null) {
                continue;
            }

            $this->counters->set($flag, '1', $budget->ttlSeconds);

            Log::info('An AI budget crossed its warning threshold', ['budget' => $budget->key, 'spent' => $spent, 'limit' => $budget->limitMicros]);

            event(new BudgetThresholdReached($budget, $feature, $spent));
        }
    }

    private function tenantId(): ?int
    {
        return LedgerEntry::tenantId();
    }

    private function usd(mixed $dollars): int
    {
        return (int) round((float) $dollars * 1_000_000);
    }
}
