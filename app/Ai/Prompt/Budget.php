<?php

namespace App\Ai\Prompt;

final class Budget
{
    public function historyAllowance(): int
    {
        $c = config('ai.budget');
        $margin = $this->margin();

        return $c['window'] - ($c['answer'] + $c['system'] + $margin + $c['context']);
    }

    public function margin(): int
    {
        $c = config('ai.budget');

        return max(200, (int) ceil($c['window'] * $c['margin_pct']));
    }

    public function assertFits(int $promptTokens): void
    {
        $c = config('ai.budget');

        if ($promptTokens + $c['answer'] > $c['window']) {
            throw new PromptTooLarge($promptTokens, $c['window']);
        }
    }
}
