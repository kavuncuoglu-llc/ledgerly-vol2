<?php

use App\Ai\Conversations\HistoryBudget;
use App\Ai\Prompt\Budget;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tokens\RatioCounter;

it('is the window minus the answer, the measured system text, the margin and the context', function () {
    $system = str_repeat('x', 3600);                                  // 1,000 tokens

    expect((new HistoryBudget(new RatioCounter))->historyAllowance($system))->toBe(8192 - (400 + 1000 + 410 + 3500));
});

it('shrinks as the system message grows, which is how memories cost history', function () {
    $budget = new HistoryBudget(new RatioCounter);

    $bare = $budget->historyAllowance(SupportPrompt::system());
    $withMemories = $budget->historyAllowance(SupportPrompt::system()."\n\n".str_repeat('- role: bookkeeper'."\n", 8));

    expect($withMemories)->toBeLessThan($bare);
});

it('uses the live window from config', function () {
    config(['ai.budget.window' => 16384]);

    expect((new HistoryBudget(new RatioCounter))->historyAllowance(str_repeat('x', 3600)))->toBe(16384 - (400 + 1000 + 820 + 3500));
});

it('agrees with the Chapter 2 budget when the system text is exactly the budgeted size', function () {
    $system = str_repeat('x', (int) (config('ai.budget.system') * 3.6));

    expect((new HistoryBudget(new RatioCounter))->historyAllowance($system))->toBe((new Budget)->historyAllowance());
});

it('leaves room for roughly three and a half thousand tokens of history at the real prompt', function () {
    $allowance = (new HistoryBudget(new RatioCounter))->historyAllowance(SupportPrompt::system());

    expect($allowance)->toBeBetween(3200, 3600);
});
