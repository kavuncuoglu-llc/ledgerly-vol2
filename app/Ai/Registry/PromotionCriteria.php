<?php

namespace App\Ai\Registry;

use App\Models\ModelRegistryEntry;

// Chapter 44, Topic 6: "promotion checks the decision record's success criteria against the stored results and refuses if any are missing or below the bar". The criteria are config('ai.registry.criteria'), written from the record's Success criteria
// paragraph (docs/adr/fine-tune-local-billing.md); the results are the eval:compare runs stored on the entry. A criterion with no result is a refusal, never a pass.
final class PromotionCriteria
{
    /** @return list<string> why the version may not be promoted; empty when it may */
    public function failures(ModelRegistryEntry $entry): array
    {
        $role = (string) (config('ai.registry.roles')[$entry->name] ?? '');

        $base = (string) config('ai.registry.base_role', 'local');

        [$mine, $theirs] = $this->latestResults($entry, $role, $base);

        if ($mine === null) {
            return ["no stored comparison measured the role {$role}: run eval:compare and keep it with registry:stage --eval=eval:last"];
        }

        $failures = [];

        foreach ((array) config('ai.registry.criteria', []) as $criterion) {
            $failure = $this->failure($criterion, $mine, $theirs, $base);

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function failure(array $criterion, array $mine, ?array $theirs, string $base): ?string
    {
        $metric = (string) $criterion['metric'];

        $value = $mine[$metric] ?? null;

        if ($value === null) {
            return "{$metric}: no result stored";
        }

        if (isset($criterion['min']) && $value < $criterion['min']) {
            return "{$metric}: {$value} is below {$criterion['min']}";
        }

        if (isset($criterion['max']) && $value > $criterion['max']) {
            return "{$metric}: {$value} is above {$criterion['max']}";
        }

        if (isset($criterion['max_vs_base'])) {
            $baseline = $theirs[$metric] ?? null;

            if ($baseline === null) {
                return "{$metric}: no result stored for {$base}, so there is nothing to compare with";
            }

            if ($value > $baseline + $criterion['max_vs_base']) {
                return "{$metric}: {$value} is above {$base}'s {$baseline}";
            }
        }

        if (isset($criterion['max_ratio_vs_base'])) {
            $baseline = $theirs[$metric] ?? null;

            if ($baseline === null) {
                return "{$metric}: no result stored for {$base}, so there is nothing to compare with";
            }

            if ($value > $baseline * $criterion['max_ratio_vs_base']) {
                return "{$metric}: {$value} is more than ".round($criterion['max_ratio_vs_base'] * 100)."% of {$base}'s {$baseline}";
            }
        }

        return null;
    }

    /** @return array{0: ?array, 1: ?array} the metrics of the role and of the base role, from the newest stored comparison that measured the role */
    private function latestResults(ModelRegistryEntry $entry, string $role, string $base): array
    {
        foreach (array_reverse((array) $entry->evaluations) as $evaluation) {
            $results = collect($evaluation['roles'] ?? [])->keyBy('role');

            if (! $results->has($role)) {
                continue;
            }

            return [$results[$role]['metrics'] ?? [], $results[$base]['metrics'] ?? null];
        }

        return [null, null];
    }
}
