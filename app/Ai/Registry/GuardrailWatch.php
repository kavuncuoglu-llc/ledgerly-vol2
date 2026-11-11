<?php

namespace App\Ai\Registry;

use App\Ai\Events\GuardrailBreached;
use App\Evals\Scorecard\Scorecard;
use App\Models\Message;
use App\Models\ModelRegistryEntry;

// Chapter 44, Topic 7: while a model version is in a trial or has just been promoted, the live scorecard by variant is read on a schedule, and every floor or never-gate a variant is under becomes a GuardrailBreached event. The listeners decide what that means:
// a breach by the canary arm ends the canary; a breach by production soon after a promotion rolls it back. Nothing is read when nothing is being watched, and a variant with too few answers is not judged on them.
class GuardrailWatch
{
    public function __construct(private Scorecard $scorecard) {}

    /** @return list<GuardrailBreached> the events dispatched */
    public function check(string $feature): array
    {
        if (! $this->watching($feature)) {
            return [];
        }

        $report = $this->scorecard->compute($feature, (int) config('ai.registry.guardrails.days', 1), byVariant: true);

        $breaches = [];

        foreach ($report['rows'] as $row) {
            foreach ($row['flags'] as $column => $flag) {
                if (str_starts_with($column, 'shadow:')) {
                    continue;                                                      // a shadow answer is never shown: it is judged, it cannot hurt a customer
                }

                if ($this->answers($column) < (int) config('ai.registry.guardrails.min_answers', 20)) {
                    continue;
                }

                $breaches[] = new GuardrailBreached($feature, $row['metric'], (float) $row['values'][$column], (float) $row['floor'], $column);
            }
        }

        foreach ($breaches as $breach) {
            GuardrailBreached::dispatch($breach->feature, $breach->metric, $breach->value, $breach->floor, $breach->variant);
        }

        return $breaches;
    }

    private function watching(string $feature): bool
    {
        $names = (array) (config('ai.registry.features')[$feature] ?? []);

        return ModelRegistryEntry::query()->whereIn('name', $names)->where(function ($query) {
            $query->whereIn('stage', ['shadow', 'canary'])
                ->orWhere(fn ($recent) => $recent->where('stage', 'production')->where('promoted_at', '>', now()->subDays((int) config('ai.registry.rollback_window_days', 7))));
        })->exists();
    }

    private function answers(string $variant): int
    {
        return Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->where('role', 'assistant')
            ->where('status', 'completed')
            ->where('updated_at', '>=', now()->subDays((int) config('ai.registry.guardrails.days', 1)))
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('experiment_variant')->orWhere('experiment_variant', 'control')), fn ($q) => $q->where('experiment_variant', $variant))
            ->count();
    }
}
