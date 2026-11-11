<?php

namespace App\Ai\Conversations;

use App\Ai\Tokens\TokenCounter;

final class HistoryBudget
{
    public function __construct(private TokenCounter $counter) {}

    public function historyAllowance(string $systemText): int
    {
        $c = config('ai.budget');

        $system = $this->counter->count($systemText);                      // measured, including memories

        $margin = max(200, (int) ceil($c['window'] * $c['margin_pct']));

        return $c['window'] - ($c['answer'] + $system + $margin + $c['context']);
    }
}
