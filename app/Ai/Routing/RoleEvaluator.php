<?php

namespace App\Ai\Routing;

use App\Ai\AiManager;
use App\Evals\GateRunner;
use App\Evals\GoldenCase;
use App\Facades\Ai;
use Closure;
use Illuminate\Support\Collection;

// Chapter 37, Topic 2: the golden set run against each role on its own. The support feature is pointed at one role at a time (no escalation, no cascade), the whole pipeline and the gates run, and the quality pass rate per category is read from
// the summary. The configuration is put back afterwards. A role that is not set up, or that the feature may not use, is not measured and the table says so.
final class RoleEvaluator
{
    public function __construct(private GateRunner $runner, private AiManager $ai) {}

    /**
     * @param  Collection<int, GoldenCase>  $cases
     * @param  string[]  $roles
     * @param  Closure(string, int, int): void|null  $progress
     * @return array{rates: array<string, array<string, float>>, skipped: array<string, string>}
     */
    public function measure(Collection $cases, array $roles, bool $similarity = false, ?Closure $progress = null): array
    {
        $rates = [];

        $skipped = [];

        foreach ($roles as $role) {
            $provider = Roles::provider($role);

            if (! $this->ai->configured($provider)) {
                $skipped[$role] = "{$provider} is not set up";

                continue;
            }

            if (! $this->ai->mayReceive('support.reply', $provider)) {
                $skipped[$role] = "support.reply may not send its data to {$provider}";

                continue;
            }

            $summary = $this->withOnlyRole($provider, fn () => $this->runner->run($cases, $similarity, $progress === null ? null : fn ($result, int $done, int $total) => $progress($role, $done, $total)));

            foreach ($summary->byCategory() as $category => $row) {
                $rates[$category][$role] = $row['quality_pass_rate'];
            }
        }

        return ['rates' => $rates, 'skipped' => $skipped];
    }

    private function withOnlyRole(string $provider, Closure $work): mixed
    {
        $features = config('ai.features');

        $cascade = config('ai.cascade.enabled');

        $budgets = config('ai.budgets.enabled');

        config([
            'ai.features' => array_replace($features, ['support.reply' => ['provider' => $provider, 'escalate_to' => null] + $features['support.reply']]),
            'ai.cascade.enabled' => false,
            'ai.budgets.enabled' => false,                                         // measuring a role must not spend a tenant's budget or be refused by it
        ]);

        Ai::forget();

        try {
            return $work();
        } finally {
            config(['ai.features' => $features, 'ai.cascade.enabled' => $cascade, 'ai.budgets.enabled' => $budgets]);

            Ai::forget();
        }
    }
}
