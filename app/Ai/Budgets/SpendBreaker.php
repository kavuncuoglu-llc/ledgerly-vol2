<?php

namespace App\Ai\Budgets;

use App\Models\AiLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Chapter 36, Topic 7: budgets catch spend that reaches a limit; a breaker catches spend that is rising fast. Each feature, and each tenant, is compared over the last fifteen minutes with what that scope normally spends at that hour
// (the median of the same window on each of the past seven days). More than five times it, and above a small floor, and the scope drops to its cheapest useful rung for thirty minutes, the owning team is told with the top traces,
// and then a trickle of calls is let through to see whether it has recovered. Closed, open and half open: the provider breaker of Volume 1's Chapter 25, watching money.
final class SpendBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    private const PREFIX = '{ai-budget}:breaker:';

    public function __construct(private Counters $counters, private Notifier $notifier) {}

    /** The pseudo-budget a call is stopped by, or null when its feature and tenant are both fine. */
    public function blocks(string $feature, ?int $tenantId): ?Budget
    {
        foreach ($this->scopes($feature, $tenantId) as $scope) {
            $state = $this->state($scope);

            if ($state === null) {
                continue;
            }

            if ($state['state'] === self::OPEN) {
                return $this->budget($scope);
            }

            if ($state['state'] === self::HALF_OPEN && ! $this->trickle($scope)) {
                return $this->budget($scope);                                      // the half-open trickle: most calls still go to the cheap rung
            }
        }

        return null;
    }

    /**
     * Compare every scope that spent in the window with its normal, and move the breakers.
     *
     * @return array<int, array{scope: string, state: string, current: int, baseline: float, ratio: ?float, changed: bool}>
     */
    public function evaluate(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $window = (int) config('ai.budgets.breaker.window_minutes', 15);

        $current = $this->spend($now->subMinutes($window), $now);

        $scopes = array_unique([...array_keys($current), ...$this->openScopes()]);

        $report = [];

        foreach ($scopes as $scope) {
            $spent = $current[$scope] ?? 0;

            $baseline = $this->baseline($scope, $now, $window);

            $tripped = $this->tripped($spent, $baseline);

            $before = $this->state($scope)['state'] ?? self::CLOSED;

            $after = $this->transition($scope, $before, $tripped, $now, $spent, $baseline);

            $report[] = ['scope' => $scope, 'state' => $after, 'current' => $spent, 'baseline' => $baseline, 'ratio' => $baseline > 0 ? $spent / $baseline : null, 'changed' => $after !== $before];
        }

        return $report;
    }

    /** @return array<string, array{state: string, opened_at: string, until: string, rate: int, baseline: float}> */
    public function states(): array
    {
        $states = [];

        foreach ($this->counters->keys(self::PREFIX.'open:') as $key) {
            $scope = substr($key, strlen(self::PREFIX.'open:'));

            $state = $this->state($scope);

            if ($state !== null) {
                $states[$scope] = $state;
            }
        }

        return $states;
    }

    public function tripped(int $currentMicros, float $baselineMicros): bool
    {
        $floor = (int) round((float) config('ai.budgets.breaker.floor_usd', 0.50) * 1_000_000);

        $ratio = (float) config('ai.budgets.breaker.ratio', 5);

        return $currentMicros >= $floor && $currentMicros > $ratio * $baselineMicros;
    }

    public function state(string $scope): ?array
    {
        $raw = $this->counters->get(self::PREFIX.'open:'.$scope);

        return $raw === null
            ? null
            : json_decode($raw, true);
    }

    private function transition(string $scope, string $before, bool $tripped, CarbonImmutable $now, int $spent, float $baseline): string
    {
        $openFor = (int) config('ai.budgets.breaker.open_minutes', 30);

        $record = fn (string $state, CarbonImmutable $until) => $this->counters->set(self::PREFIX.'open:'.$scope, json_encode(['state' => $state, 'opened_at' => $now->toIso8601String(), 'until' => $until->toIso8601String(), 'rate' => $spent, 'baseline' => $baseline]), 86_400);

        if ($before === self::CLOSED) {
            if (! $tripped) {
                return self::CLOSED;
            }

            $record(self::OPEN, $now->addMinutes($openFor));

            $this->announce($scope, $spent, $baseline, $now);

            return self::OPEN;
        }

        $state = $this->state($scope);

        if ($before === self::OPEN) {
            return CarbonImmutable::parse($state['until'])->lte($now)
                ? $this->halfOpen($scope, $state)
                : self::OPEN;
        }

        if ($tripped) {
            $record(self::OPEN, $now->addMinutes($openFor));                       // the trickle was still spending like the loop: open again

            return self::OPEN;
        }

        $this->counters->forget(self::PREFIX.'open:'.$scope);                       // recovered after a successful trickle of calls

        return self::CLOSED;
    }

    private function halfOpen(string $scope, array $state): string
    {
        $state['state'] = self::HALF_OPEN;

        $this->counters->set(self::PREFIX.'open:'.$scope, json_encode($state), 86_400);

        return self::HALF_OPEN;
    }

    private function announce(string $scope, int $spent, float $baseline, CarbonImmutable $now): void
    {
        [$kind, $name] = explode(':', $scope, 2);

        $window = (int) config('ai.budgets.breaker.window_minutes', 15);

        $traces = AiLedgerEntry::query()
            ->where('occurred_at', '>=', $now->subMinutes($window))
            ->when($kind === 'feature', fn ($q) => $q->where('feature', $name), fn ($q) => $q->where('tenant_id', (int) $name))
            ->whereNotNull('trace_id')
            ->orderByDesc('cost_micro_usd')
            ->limit(3)
            ->pluck('trace_id')
            ->all();

        $team = $kind === 'feature'
            ? Teams::of($name)
            : 'finance';

        $this->notifier->team($team, "AI spend breaker open: {$scope}", sprintf(
            '%s spent $%s in the last %d minutes against a normal of $%s. It is on its cheapest rung for %d minutes. Top traces: %s',
            $scope,
            number_format($spent / 1_000_000, 2),
            $window,
            number_format($baseline / 1_000_000, 2),
            (int) config('ai.budgets.breaker.open_minutes', 30),
            $traces === [] ? 'none recorded' : implode(', ', $traces),
        ));
    }

    /** @return array<string, int> scope => micro-dollars, for features and tenants */
    private function spend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = AiLedgerEntry::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->select('feature', 'tenant_id', DB::raw('sum(cost_micro_usd) as micros'))
            ->groupBy('feature', 'tenant_id')
            ->get();

        $scopes = [];

        foreach ($rows as $row) {
            $scopes["feature:{$row->feature}"] = ($scopes["feature:{$row->feature}"] ?? 0) + (int) $row->micros;

            if ($row->tenant_id !== null) {
                $scopes["tenant:{$row->tenant_id}"] = ($scopes["tenant:{$row->tenant_id}"] ?? 0) + (int) $row->micros;
            }
        }

        return $scopes;
    }

    // The median, over the past seven days, of what this scope spent in the same window of the day.
    public function baseline(string $scope, CarbonImmutable $now, int $window): float
    {
        $days = Collection::times(7, function (int $day) use ($scope, $now, $window) {
            return $this->spend($now->subDays($day)->subMinutes($window), $now->subDays($day))[$scope] ?? 0;
        })->sort()->values();

        return (float) $days[(int) floor(($days->count() - 1) / 2)];
    }

    /** @return string[] */
    private function openScopes(): array
    {
        return array_map(fn (string $key) => substr($key, strlen(self::PREFIX.'open:')), $this->counters->keys(self::PREFIX.'open:'));
    }

    /** @return string[] */
    private function scopes(string $feature, ?int $tenantId): array
    {
        return array_values(array_filter(["feature:{$feature}", $tenantId === null ? null : "tenant:{$tenantId}"]));
    }

    private function budget(string $scope): Budget
    {
        return new Budget(self::PREFIX.'open:'.$scope, 'breaker', "spend breaker ({$scope})", 0, 0, $scope);
    }

    // One call in ten is let through while half open.
    private function trickle(string $scope): bool
    {
        $key = self::PREFIX.'trickle:'.$scope;

        $this->counters->adjust($key, 1, 3600);

        return $this->counters->value($key) % (int) config('ai.budgets.breaker.trickle_every', 10) === 0;
    }
}
