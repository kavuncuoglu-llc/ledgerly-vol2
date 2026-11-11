<?php

namespace App\Ai\Events;

use Illuminate\Foundation\Events\Dispatchable;

// Chapter 44, Topic 7: a feature crossed a guardrail on live traffic: a scorecard floor, a never-gate, or a spend breaker. `variant` says whose traffic: null or `control` is the production version, `treatment` is a canary's.
final class GuardrailBreached
{
    use Dispatchable;

    public function __construct(
        public readonly string $feature,
        public readonly string $metric,
        public readonly float $value,
        public readonly float $floor,
        public readonly ?string $variant = null,
    ) {}
}
