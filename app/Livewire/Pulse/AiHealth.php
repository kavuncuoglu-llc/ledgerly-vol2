<?php

namespace App\Livewire\Pulse;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

// Chapter 31, Topic 7: time to first token per feature, and how often each provider's circuit breaker (Volume 1, Chapter 25) opened.
#[Lazy]
class AiHealth extends Card
{
    public function render(): Renderable
    {
        [$data, $time, $runAt] = $this->remember(fn () => [
            'ttft' => $this->aggregate('ai_ttft_ms', ['avg', 'max'], 'max')->map(fn ($row) => (object) ['feature' => $row->key, 'avg' => (int) $row->avg, 'max' => (int) $row->max]),
            'trips' => $this->aggregate('ai_breaker_trip', ['count'], 'count')->map(fn ($row) => (object) ['provider' => $row->key, 'count' => (int) $row->count]),
        ]);

        return View::make('livewire.pulse.ai-health', [
            'time' => $time,
            'runAt' => $runAt,
            'ttft' => $data['ttft'],
            'trips' => $data['trips'],
        ]);
    }
}
