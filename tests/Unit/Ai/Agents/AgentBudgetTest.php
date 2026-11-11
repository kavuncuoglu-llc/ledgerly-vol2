<?php

use App\Ai\Agents\AgentBudget;
use App\Ai\Agents\AgentState;
use App\Ai\Contracts\Usage;
use App\Ai\Tools\ToolKind;

function budget(int $rounds = 5, int $tokens = 20_000, int $seconds = 60, int $writes = 1): AgentBudget
{
    return new AgentBudget($rounds, $tokens, $seconds, $writes);
}

it('allows a fresh run and counts rounds and tokens', function () {
    $budget = budget(rounds: 2);

    $state = AgentState::fresh('goal');

    expect($budget->allows($state))->toBeTrue();

    $budget->recordRound(new Usage(100, 20));

    expect($budget->rounds())->toBe(1)
        ->and($budget->tokens())->toBe(120)
        ->and($budget->allows($state->withFinding('a', 1)))->toBeTrue();

    $budget->recordRound(new Usage(100, 20));

    expect($budget->allows($state->withFinding('a', 2)))->toBeFalse()
        ->and($budget->reason())->toBe('rounds');
});

it('stops on tokens', function () {
    $budget = budget(tokens: 500);

    $budget->recordRound(new Usage(400, 150));

    expect($budget->allows(AgentState::fresh('goal')))->toBeFalse()
        ->and($budget->reason())->toBe('tokens');
});

it('stops on time', function () {
    expect(budget(seconds: 0)->allows(AgentState::fresh('goal')))->toBeFalse();

    $budget = budget(seconds: 0);

    $budget->allows(AgentState::fresh('goal'));

    expect($budget->reason())->toBe('time');
});

it('counts only write attempts, and stops on writes', function () {
    $budget = budget(writes: 2);

    $budget->recordTool(ToolKind::Read);
    $budget->recordTool(ToolKind::WriteLowRisk);

    expect($budget->writes())->toBe(1)
        ->and($budget->allows(AgentState::fresh('goal')))->toBeTrue();

    $budget->recordTool(ToolKind::WriteHighRisk);

    expect($budget->allows(AgentState::fresh('goal')->withFinding('x', 1)))->toBeFalse()
        ->and($budget->reason())->toBe('writes');
});

it('ends a stuck run: two identical states in a row', function () {
    $budget = budget(rounds: 20);

    $state = AgentState::fresh('goal')->withFinding('invoice', ['number' => 'INV-4471']);

    expect($budget->allows($state))->toBeTrue()          // first sight
        ->and($budget->allows($state))->toBeTrue()       // one repeat is tolerated
        ->and($budget->allows($state))->toBeFalse()      // two is stuck
        ->and($budget->reason())->toBe('no_progress');
});

it('does not count a changed state as stuck, and the messages are not part of the state hash', function () {
    $budget = budget(rounds: 20);

    $state = AgentState::fresh('goal');

    $budget->allows($state);
    $budget->allows($state->withMessage(['role' => 'user', 'content' => 'again']));

    expect($budget->allows($state->withMessage(['role' => 'user', 'content' => 'and again'])))->toBeFalse()
        ->and($budget->allows($state->withFinding('new', 1)))->toBeTrue();
});

it('carries the rounds already spent when a paused run resumes', function () {
    $budget = new AgentBudget(5, 20_000, 60, 1, roundsAlreadyUsed: 4);

    expect($budget->rounds())->toBe(4);

    $budget->recordRound(new Usage(1, 1));

    expect($budget->allows(AgentState::fresh('g')))->toBeFalse();
});

it('is read from config, and refuses an agent it has no numbers for', function () {
    expect(AgentBudget::fromConfig('triage')->limits())->toBe(['max_rounds' => 8, 'max_tokens' => 30_000, 'max_seconds' => 120, 'max_writes' => 2])
        ->and(fn () => AgentBudget::fromConfig('nobody'))->toThrow(InvalidArgumentException::class);
});

it('has a state that survives a round trip through the database', function () {
    $state = AgentState::fresh('Gather facts', ['invoice_number' => '4471'], [['role' => 'user', 'content' => 'hi']])
        ->withFinding('invoices', ['number' => 'INV-4471'], append: true);

    $copy = AgentState::fromArray(json_decode(json_encode($state->toArray()), true));

    expect($copy->hash())->toBe($state->hash())
        ->and($copy->messages)->toBe($state->messages)
        ->and($copy->id)->toBe($state->id)
        ->and($state->toArray(withMessages: false)['messages'])->toBe([]);
});
