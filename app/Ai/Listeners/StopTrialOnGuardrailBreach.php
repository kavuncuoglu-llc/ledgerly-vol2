<?php

namespace App\Ai\Listeners;

use App\Ai\Events\GuardrailBreached;
use App\Facades\Registry;

// Chapter 44, Figure 44-12: "canary v2 stopped by its guardrail · v1 keeps all traffic". A breach on the treatment arm ends the trial and rejects the version with the reason; the team is told through the registry's own notice.
final class StopTrialOnGuardrailBreach
{
    public function handle(GuardrailBreached $event): void
    {
        if ($event->variant !== 'treatment') {
            return;
        }

        foreach ((config('ai.registry.features', [])[$event->feature] ?? []) as $name) {
            Registry::stopTrial($name, "{$event->feature} {$event->metric} {$event->value} < floor {$event->floor}: canary stopped by its guardrail", 'guardrail');
        }
    }
}
