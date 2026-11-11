<?php

namespace App\Livewire\Pulse;

use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Budgets\SpendBreaker;
use App\Models\AiLedgerEntry;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

// Chapter 36, Topic 9: the same numbers as ai:spend, as a card. The app's hour against its ceiling, every breaker that is open or half open, every kill switch that is on, and today's unattributed spend (spend that belongs to nobody is spend nobody will notice growing).
#[Lazy]
class AiBudgets extends Card
{
    public function render(BudgetGuard $guard, Counters $counters, SpendBreaker $breaker, KillSwitch $kill): Renderable
    {
        $hour = $guard->appHour();

        $today = AiLedgerEntry::query()->where('occurred_at', '>=', now()->startOfDay());

        $total = (int) (clone $today)->sum('cost_micro_usd');

        $unowned = (int) (clone $today)->where(fn ($query) => $query->where('team', 'unowned')->orWhere('feature', 'unattributed'))->sum('cost_micro_usd');

        return View::make('livewire.pulse.ai-budgets', [
            'hourSpent' => $counters->value($hour->key) / 1_000_000,
            'hourLimit' => $hour->limitMicros / 1_000_000,
            'breakers' => $breaker->states(),
            'kills' => $kill->active(),
            'unattributedShare' => $total > 0
                ? $unowned / $total
                : 0.0,
        ]);
    }
}
