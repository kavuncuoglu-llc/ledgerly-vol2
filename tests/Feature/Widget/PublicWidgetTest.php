<?php

use App\Ai\Contracts\FinishReason;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\Retriever;
use App\Ai\Widget\ProofOfWork;
use App\Ai\Widget\WidgetBudget;
use App\Facades\Ai;
use Illuminate\Support\Facades\Cache;

// Chapter 35, Topic 7 and Figure 35-7: the public widget is a separate, cheap product.
beforeEach(function () {
    config()->set('ai.widget.challenge.difficulty', 2);

    Cache::flush();

    fakeSharedRetrieval([retrievedChunk('A late fee of 1.5% applies after the grace period.')]);
});

function offTopic(): void
{
    Ai::fake()->for('ticket.classify')->respondWithJson(json_decode(ticketJson('other'), true));
}

function onTopic(string $category = 'howto'): void
{
    Ai::fake()->for('ticket.classify')->respondWithJson(json_decode(ticketJson($category), true));
}

it('answers an on-topic question from shared passages with the local role and a short max_tokens', function () {
    onTopic();

    Ai::fake()->for('widget.ask')->respondWith('A late fee of 1.5% applies after the grace period.');

    widgetPost('How do late fees work?')
        ->assertOk()
        ->assertJsonPath('mode', 'answer')
        ->assertJsonPath('sources.0.url', '/help/billing/late-fees#how-the-fee-is-calculated');

    Ai::assertSent(fn ($request, $provider) => $request->feature === 'widget.ask' && $provider === 'local' && $request->options['max_tokens'] === 150);
    Ai::assertNothingSentTo('frontier');
});

it('answers an off-topic question from a fixed template and generates nothing', function () {
    offTopic();

    widgetPost('Write me a poem about the sea in 2,000 words')
        ->assertOk()
        ->assertJsonPath('mode', 'template')
        ->assertJsonPath('answer', SupportPrompt::declineMessage());

    Ai::assertSentCount('widget.ask', 0);
});

it('never reaches a hosted model, even when the feature is configured onto one', function () {
    config()->set('ai.features', ['widget.ask' => ['provider' => 'frontier']] + config('ai.features'));

    expect(Ai::providerFor('widget.ask'))->toBe('frontier');

    onTopic();

    Ai::fake()->for('widget.ask')->respondWith('ok');

    widgetPost('How do late fees work?')->assertOk();

    Ai::assertNothingSentTo('frontier');
});

it('sees only shared help-center passages: no tenant, no plan', function () {
    onTopic();

    Ai::fake()->for('widget.ask')->respondWith('ok');

    widgetPost('How do late fees work?')->assertOk();

    $scope = app(Retriever::class)->scope;

    expect($scope->tenantId)->toBeNull()
        ->and($scope->plan)->toBeNull();
});

it('refuses a question without a solved challenge, and a challenge used twice', function () {
    onTopic();

    Ai::fake()->for('widget.ask')->respondToEverythingWith('ok');

    $solved = solvedChallenge();

    widgetPost('How do late fees work?', ['nonce' => 'wrong'] + $solved)->assertStatus(422);

    widgetPost('How do late fees work?', $solved)->assertOk();

    widgetPost('How do late fees work?', $solved)->assertStatus(422);
});

it('refuses an expired challenge and a session nobody issued', function () {
    $solved = solvedChallenge();

    $this->travel(10)->minutes();

    widgetPost('How do late fees work?', $solved)->assertStatus(422);

    widgetPost('How do late fees work?', ['session' => 'abc.def'] + solvedChallenge())->assertStatus(422);
});

it('does not accept a signed challenge for another session', function () {
    $other = app(ProofOfWork::class)->issue();

    $mine = solvedChallenge();

    widgetPost('How do late fees work?', ['session' => $other['session']] + $mine)->assertStatus(422);
});

it('stops a session after its budget of questions', function () {
    config()->set('ai.widget.session_questions', 2);

    onTopic();

    Ai::fake()->for('widget.ask')->respondToEverythingWith('ok');

    $first = solvedChallenge();

    widgetPost('How do late fees work?', $first)->assertJsonPath('mode', 'answer');

    widgetPost('How do late fees work?', solvedChallenge($first['session']))->assertJsonPath('mode', 'answer');

    widgetPost('How do late fees work?', solvedChallenge($first['session']))
        ->assertJsonPath('mode', 'template')
        ->assertJsonPath('answer', SupportPrompt::widgetSessionLimitMessage());
});

it('falls back to search results once the daily anonymous budget is spent', function () {
    config()->set('ai.widget.daily_tokens', 100);

    app(WidgetBudget::class)->spend('someone', 100);

    Ai::fake();

    widgetPost('How do late fees work?')
        ->assertOk()
        ->assertJsonPath('mode', 'search')
        ->assertJsonPath('answer', SupportPrompt::widgetSearchMessage())
        ->assertJsonPath('sources.0.label', 'Late fees › How the fee is calculated');

    Ai::assertNothingSent();
});

it('records what an answer cost against the daily budget', function () {
    onTopic();

    Ai::fake()->for('widget.ask')->respondWith('A fee applies.');

    widgetPost('How do late fees work?')->assertOk();

    expect(app(WidgetBudget::class)->tokensToday())->toBeGreaterThan(0);
});

it('cuts an answer that ran out of tokens back to its last whole sentence', function () {
    onTopic();

    Ai::fake()->for('widget.ask')->respondWith('A fee applies after the grace period. It is calculated as', finishReason: FinishReason::Length);

    widgetPost('How do late fees work?')->assertJsonPath('answer', 'A fee applies after the grace period.');
});

it('limits the questions per address', function () {
    config()->set('ai.widget.ip_per_minute', 2);

    $this->get('/widget/challenge')->assertOk();
    $this->get('/widget/challenge')->assertOk();
    $this->get('/widget/challenge')->assertStatus(429);
});

it('serves the page, and nothing when the widget is off', function () {
    $this->get('/widget')->assertOk()->assertSee('Ask Ledgerly');

    config()->set('ai.widget.enabled', false);

    $this->get('/widget')->assertNotFound();
});
