<?php

namespace App\Ai\Widget;

use Illuminate\Support\Facades\Cache;

// Chapter 35, Topic 7: anonymous visitors have no tenant to charge, so they get budgets of their own: a few questions per session, and a daily ceiling for everyone together, in tokens.
// When the daily ceiling is spent the widget stops generating and offers search results. (Chapter 36 puts the same ceiling in dollars under BudgetGuard, with the kill switch.)
final class WidgetBudget
{
    public function sessionHasRoom(string $session): bool
    {
        return (int) Cache::get($this->sessionKey($session), 0) < (int) config('ai.widget.session_questions', 8);
    }

    public function dayHasRoom(): bool
    {
        return $this->tokensToday() < (int) config('ai.widget.daily_tokens', 1_500_000);
    }

    public function tokensToday(): int
    {
        return (int) Cache::get($this->dayKey(), 0);
    }

    public function spend(string $session, int $tokens): void
    {
        Cache::add($this->sessionKey($session), 0, 3600);

        Cache::increment($this->sessionKey($session));

        Cache::add($this->dayKey(), 0, 90_000);

        Cache::increment($this->dayKey(), max(0, $tokens));
    }

    private function sessionKey(string $session): string
    {
        return 'widget:session:'.hash('sha256', $session);
    }

    private function dayKey(): string
    {
        return 'widget:tokens:'.now()->toDateString();
    }
}
