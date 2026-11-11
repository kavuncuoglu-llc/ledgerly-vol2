<?php

use App\Ai\Prompt\Budget;
use App\Ai\Prompt\PromptTooLarge;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tokens\TokenCounter;

it('reproduces the Figure 2-8 allowances for an 8,192-token window', function () {
    config(['ai.budget.window' => 8192]);

    $budget = new Budget;

    expect($budget->margin())->toBe(410)
        ->and($budget->historyAllowance())->toBe(3432);
});

it('adds up to the whole window', function () {
    $budget = new Budget;
    $c = config('ai.budget');

    expect($c['answer'] + $c['system'] + $budget->margin() + $c['context'] + $budget->historyAllowance())
        ->toBe((int) $c['window']);
});

it('never leaves a margin below 200 tokens', function () {
    config(['ai.budget.window' => 2000]);

    expect((new Budget)->margin())->toBe(200);
});

it('accepts a prompt that leaves room for the answer', function () {
    config(['ai.budget.window' => 8192, 'ai.budget.answer' => 400]);

    (new Budget)->assertFits(7792);

    expect(true)->toBeTrue();
});

it('rejects a prompt that leaves no room for the answer', function () {
    config(['ai.budget.window' => 8192, 'ai.budget.answer' => 400]);

    (new Budget)->assertFits(7793);
})->throws(PromptTooLarge::class, 'Prompt of 7793 tokens');

it('keeps the support system prompt inside the system budget', function () {
    $tokens = app(TokenCounter::class)->count(SupportPrompt::system());

    expect($tokens)->toBeLessThanOrEqual(config('ai.budget.system'));
});

it('has no real API key in the testing environment', function () {
    expect(config('ai.api_key'))->not->toStartWith('sk-')
        ->and(config('ai.api_key'))->toBe('ollama')
        ->and(config('ai.providers.frontier.api_key'))->toBeEmpty()          // a reader's real FRONTIER_KEY never reaches the suite
        ->and(config('ai.providers.frontier.base_url'))->toBeEmpty();
});
