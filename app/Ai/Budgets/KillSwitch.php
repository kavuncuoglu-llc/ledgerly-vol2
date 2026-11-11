<?php

namespace App\Ai\Budgets;

use App\Ai\Events\EmergencyKillSwitchActivated;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

// Chapter 36, Topic 7: an immediate stop of all spend on a provider, a feature or the whole app. It fires by itself when the app's hourly ceiling is hit, or by hand with `ai:kill`. It never resets itself: a person does, with a reason.
final class KillSwitch
{
    public const PREFIX = '{ai-budget}:kill:';

    public function __construct(private Counters $counters) {}

    public function activate(string $scope, string $reason, ?User $by = null): void
    {
        $this->assertScope($scope);

        $this->counters->set(self::PREFIX.$scope, json_encode(['reason' => $reason, 'by' => $by?->id, 'at' => now()->toIso8601String()]));

        Log::critical('AI kill switch activated', ['scope' => $scope, 'reason' => $reason, 'by' => $by?->id]);

        event(new EmergencyKillSwitchActivated($scope, $reason, $by));            // page on-call, notify, bottom-rung banner
    }

    public function reset(string $scope, string $reason, ?User $by = null): bool
    {
        $this->assertScope($scope);

        if ($this->counters->get(self::PREFIX.$scope) === null) {
            return false;
        }

        $this->counters->forget(self::PREFIX.$scope);

        Log::warning('AI kill switch reset', ['scope' => $scope, 'reason' => $reason, 'by' => $by?->id]);

        return true;
    }

    // The first thing BudgetGuard checks: the whole app, the provider this call is going to, the feature making it.
    public function isActive(string $feature, string $provider): bool
    {
        return $this->activeScope($feature, $provider) !== null;
    }

    public function activeScope(string $feature, string $provider): ?string
    {
        foreach (['global', "provider:{$provider}", "feature:{$feature}"] as $scope) {
            if ($this->counters->get(self::PREFIX.$scope) !== null) {
                return $scope;
            }
        }

        return null;
    }

    /** @return array<string, array{reason: string, by: ?int, at: string}> */
    public function active(): array
    {
        $active = [];

        foreach ($this->counters->keys(self::PREFIX) as $key) {
            $payload = json_decode((string) $this->counters->get($key), true);

            if (is_array($payload)) {
                $active[substr($key, strlen(self::PREFIX))] = $payload;
            }
        }

        return $active;
    }

    private function assertScope(string $scope): void
    {
        if ($scope === 'global' || preg_match('/^(provider|feature):[A-Za-z0-9._-]+$/', $scope) === 1) {
            return;
        }

        throw new InvalidArgumentException("A kill switch scope is global, provider:<name> or feature:<name>, not [{$scope}].");
    }
}
