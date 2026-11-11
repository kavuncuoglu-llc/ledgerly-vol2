<?php

namespace App\Ai\Listeners;

use App\Ai\Budgets\Notifier;
use App\Ai\Events\GuardrailBreached;
use App\Facades\Registry;
use InvalidArgumentException;

// Chapter 44, Figure 44-7: the same reflex as a kill switch. A guardrail breach on the production version soon after a promotion rolls the promotion back and pages the owning team; a person reviews before trying again.
final class RollBackOnGuardrailBreach
{
    public function __construct(private Notifier $notifier) {}

    public function handle(GuardrailBreached $event): void
    {
        if ($event->variant !== null && $event->variant !== 'control') {
            return;                                                                // a trial's own breach is StopTrialOnGuardrailBreach's
        }

        $entry = Registry::productionFor($event->feature);

        if ($entry === null) {
            return;
        }

        if ($entry->promoted_at === null) {
            return;
        }

        if ($entry->promoted_at->lte(now()->subDays((int) config('ai.registry.rollback_window_days', 7)))) {
            return;                                                                // a recent promotion is the likely cause; an old one is not
        }

        try {
            Registry::rollback($entry->name, reason: "auto: {$event->metric} {$event->value} below floor {$event->floor}", by: 'RollBackOnGuardrailBreach');
        } catch (InvalidArgumentException $e) {
            $this->notifier->oncall("Guardrail breached on {$entry->label()} and it could not be rolled back", $e->getMessage());
        }
    }
}
