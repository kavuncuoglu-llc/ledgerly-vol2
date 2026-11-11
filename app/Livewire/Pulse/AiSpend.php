<?php

namespace App\Livewire\Pulse;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

// Chapter 31, Topic 7: tokens and cost per feature over the period, from the aggregates UsageLoggingModel records. The cost guardrail on the scorecard (Chapter 30) is the first thing this makes visible.
#[Lazy]
class AiSpend extends Card
{
    public function render(): Renderable
    {
        [$features, $time, $runAt] = $this->remember(function () {
            $tokens = $this->aggregate('ai_tokens', ['sum'], 'sum')->keyBy('key');

            $cost = $this->aggregate('ai_cost_microusd', ['sum'])->keyBy('key');

            return $tokens->map(fn ($row, $feature) => (object) [
                'feature' => $feature,
                'tokens' => (int) $row->sum,
                'costUsd' => ((int) ($cost[$feature]->sum ?? 0)) / 1_000_000,
            ])->values();
        });

        return View::make('livewire.pulse.ai-spend', [
            'time' => $time,
            'runAt' => $runAt,
            'features' => $features,
        ]);
    }
}
