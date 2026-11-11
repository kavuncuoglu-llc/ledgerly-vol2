<?php

use App\Ai\Budgets\ArrayCounters;
use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\Counters;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Support\SupportAssistant;
use App\Facades\Ai;
use App\Models\MessageSource;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Context;

// Chapter 36, Topic 5 and Figure 36-5: when a budget is spent the support assistant falls down its ladder, and a customer always gets something useful.
beforeEach(function () {
    spendControlsOn();

    config([
        'ai.features' => ['support.reply' => config('ai.features')['support.reply']] + config('ai.features'),
        'ai.tiers.mid' => 'unconfigured-mid',
    ]);

    config()->set('ai.features', array_merge(config('ai.features'), ['support.reply' => array_merge(config('ai.features')['support.reply'], ['provider' => 'frontier'])]));

    [$this->user] = customerWithInvoice();

    fakeSharedRetrieval([retrievedChunk('A late fee of 1.5% applies after the grace period.')]);

    Ai::fake()->for('support.reply')->respondToEverythingWith('A late fee of 1.5% applies after the grace period. [late-fees › How the fee is calculated]');
});

function providersThatAnswered(): array
{
    return collect(Ai::fake()->sent())->where(fn ($call) => $call['request']->feature === 'support.reply')->pluck('provider')->values()->all();
}

function spendDownTo(int $micros): void
{
    config()->set('ai.budgets.feature_day_usd', ['support.reply' => $micros / 1_000_000, '*' => 10.0]);
}

it('answers from the frontier role while every budget has room', function () {
    $reply = runAnswerQuestion('How are late fees calculated?', $this->user);

    expect($reply->status)->toBe('completed')
        ->and(providersThatAnswered())->toBe(['frontier'])
        ->and($reply->content)->toContain('1.5%');
});

it('rung 1: sends the question to a cheaper role and answers in full', function () {
    spendDownTo(100);

    $reply = runAnswerQuestion('How are late fees calculated?', $this->user);

    expect($reply->status)->toBe('completed')
        ->and(providersThatAnswered())->toBe(['local'])
        ->and($reply->content)->toContain('1.5%')
        ->and(ModelCall::where('provider', 'degraded')->where('model', 'budget:cheaper_role')->exists())->toBeTrue();
});

it('rung 2: with no cheaper role, shorter answers and fewer passages on the same role', function () {
    config()->set('ai.tiers.small', 'unconfigured-small');

    fakeSharedRetrieval([retrievedChunk('A late fee of 1.5% applies after the grace period.'), retrievedChunk('Second.', heading: 'Late fees › Two'), retrievedChunk('Third.', heading: 'Late fees › Three')]);

    runAnswerQuestion('How are late fees calculated?', $this->user);                      // what a normal call is reserved for

    $request = collect(Ai::fake()->sent())->where(fn ($call) => $call['provider'] === 'frontier')->last()['request'];

    $normal = app(BudgetGuard::class)->estimateMicroUsd($request, 'frontier', 'frontier-example');

    app()->instance(Counters::class, new ArrayCounters);

    Ai::forget();

    Context::flush();

    spendDownTo($normal - 2_500);                                                         // a normal call no longer fits; the same call with 200 output tokens and two passages does

    $tighter = runAnswerQuestion('How are late fees calculated now?', $this->user);

    $sent = collect(Ai::fake()->sent())->where(fn ($call) => $call['provider'] === 'frontier')->last();

    expect($tighter->status)->toBe('completed')
        ->and($tighter->content)->toContain('1.5%')
        ->and($sent['request']->options['max_tokens'])->toBe(200);
});

it('rung 3 serves only a cached answer, and only for a question the cache policy allows', function () {
    config()->set('ai.semantic_cache.enabled', true);

    $assistant = app(SupportAssistant::class);

    $method = new ReflectionMethod($assistant, 'fromCacheOnly');

    $conversation = conversationFor($this->user);

    expect($method->invoke($assistant, $conversation, 'How are late fees calculated?', null, $this->user))->toBeNull();         // nothing cached

    config()->set('ai.semantic_cache.enabled', false);

    expect($method->invoke($assistant, $conversation, 'How are late fees calculated?', null, $this->user))->toBeNull();
});

it('rung 4: help-center search results and a way to reach a person, never an error', function () {
    spendDownTo(100);

    config()->set('ai.tiers.small', 'unconfigured-small');

    $reply = runAnswerQuestion('How are late fees calculated?', $this->user);

    $sources = MessageSource::query()->where('message_id', $reply->id)->get(['label', 'url']);

    expect($reply->status)->toBe('completed')
        ->and($reply->content)->toBe(SupportPrompt::budgetMessage())
        ->and($reply->content)->toContain('open a ticket')
        ->and($sources)->not->toBeEmpty()
        ->and($sources->first()->url)->toContain('/help/billing/late-fees');
});

it('with the kill switch on, answers with search results and a link to a person, and the model is not called', function () {
    app(KillSwitch::class)->activate('global', 'drill');

    $reply = runAnswerQuestion('How are late fees calculated?', $this->user);

    expect($reply->status)->toBe('completed')
        ->and($reply->content)->toBe(SupportPrompt::budgetMessage())
        ->and(MessageSource::query()->where('message_id', $reply->id)->count())->toBeGreaterThan(0)
        ->and(providersThatAnswered())->toBe([])
        ->and(ModelCall::where('model', 'budget:search')->exists())->toBeTrue();
});

it('shows the same thing when only the frontier provider is stopped, and answers from a role that is not', function () {
    app(KillSwitch::class)->activate('provider:frontier', 'drill');

    $reply = runAnswerQuestion('How are late fees calculated?', $this->user);

    expect($reply->status)->toBe('completed')
        ->and($reply->content)->toBe(SupportPrompt::budgetMessage());             // the feature is on frontier: its switch is the bottom rung, as the book says
});

it('puts the reservation back when the call it was for fails', function () {
    Ai::fake()->for('support.reply')->respondWithError(new RuntimeException('provider fell over'));

    try {
        runAnswerQuestion('How are late fees calculated?', $this->user);
    } catch (Throwable) {
        // the job reports it
    }

    foreach (app(BudgetGuard::class)->budgetsFor('support.reply') as $budget) {
        expect(spendCounters()->value($budget->key))->toBe(0);
    }
});
