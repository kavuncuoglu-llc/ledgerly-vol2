<?php

use App\Ai\Budgets\Notifier;
use App\Ai\Events\GuardrailBreached;
use App\Ai\Listeners\RollBackOnGuardrailBreach;
use App\Ai\Listeners\StopTrialOnGuardrailBreach;
use App\Ai\Registry\GuardrailWatch;
use App\Evals\Scorecard\Scorecard;
use App\Facades\Registry;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelRegistryEntry;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistryWorld;

// Chapter 44, Topic 7, Figure 44-7 and Figure 44-12: a canary is stopped by its guardrail, a fresh promotion is rolled back by one, and a person reviews before trying again.
beforeEach(function () {
    RegistryWorld::boot();

    config(['ai.budgets.notify.channel_webhook' => 'https://hooks.example.com/ai']);

    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
});

afterEach(function () {
    RegistryWorld::clean();
});

it('rolls back a promotion that is days old when a floor is crossed, and pages the team', function () {
    RegistryWorld::entry('billing', 1, 'previous', ['warm_until' => now()->addDays(5)]);
    RegistryWorld::entry('billing', 2, 'production', ['promoted_at' => now()->subDays(2)]);

    GuardrailBreached::dispatch('support.reply', 'required_facts', 0.71, 0.95, 'control');

    $v2 = ModelRegistryEntry::query()->where('version', 2)->first();

    expect($v2->stage)->toBe('rejected')
        ->and(collect($v2->history)->last())->toMatchArray(['by' => 'RollBackOnGuardrailBreach', 'reason' => 'rolled back: auto: required_facts 0.71 below floor 0.95'])
        ->and(ModelRegistryEntry::query()->where('version', 1)->value('stage'))->toBe('production');

    Http::assertSent(fn ($request) => str_contains($request['text'], '[PAGE] Model registry: billing rolled back from v2 to v1'));
});

it('leaves an old promotion alone: a breach that late is not the promotion\'s doing', function () {
    RegistryWorld::entry('billing', 1, 'previous', ['warm_until' => now()->addDays(5)]);
    RegistryWorld::entry('billing', 2, 'production', ['promoted_at' => now()->subDays(30)]);

    GuardrailBreached::dispatch('support.reply', 'required_facts', 0.71, 0.95);

    expect(ModelRegistryEntry::query()->where('version', 2)->value('stage'))->toBe('production');
});

it('pages a person when a promotion should be rolled back and there is nothing to roll back to', function () {
    RegistryWorld::entry('billing', 2, 'production', ['promoted_at' => now()->subDay()]);

    GuardrailBreached::dispatch('support.reply', 'never_gate_failures', 3.0, 0.0, 'control');

    expect(ModelRegistryEntry::query()->value('stage'))->toBe('production');

    Http::assertSent(fn ($request) => str_contains($request['text'], 'could not be rolled back'));
});

it('does not roll production back for a breach by the canary arm, and stops the canary instead', function () {
    RegistryWorld::entry('billing', 1, 'production', ['promoted_at' => now()->subDay()]);
    RegistryWorld::entry('billing', 2, 'canary', ['percent' => 10]);

    GuardrailBreached::dispatch('support.reply', 'required_facts', 0.71, 0.95, 'treatment');

    $canary = ModelRegistryEntry::query()->where('version', 2)->first();

    expect(ModelRegistryEntry::query()->where('version', 1)->value('stage'))->toBe('production')
        ->and($canary->stage)->toBe('rejected')
        ->and(collect($canary->history)->last()['reason'])->toBe('support.reply required_facts 0.71 < floor 0.95: canary stopped by its guardrail');
});

it('ignores a breach on a feature no fine-tune serves', function () {
    RegistryWorld::entry('billing', 1, 'production', ['promoted_at' => now()->subDay()]);

    GuardrailBreached::dispatch('ticket.classify', 'agreement_with_agent_label', 0.5, 0.88, 'control');

    expect(ModelRegistryEntry::query()->value('stage'))->toBe('production');
});

it('registers both listeners', function () {
    expect(Event::hasListeners(GuardrailBreached::class))->toBeTrue();

    expect(app(RollBackOnGuardrailBreach::class))->toBeInstanceOf(RollBackOnGuardrailBreach::class)
        ->and(app(StopTrialOnGuardrailBreach::class))->toBeInstanceOf(StopTrialOnGuardrailBreach::class)
        ->and(app(Notifier::class))->toBeInstanceOf(Notifier::class);
});

// The watch: read the live scorecard by variant only while something is being watched, and judge a variant only on enough answers.
function scorecardSays(array $flags, array $values, float $floor = 0.95): void
{
    $report = ['feature' => 'support.reply', 'from' => '', 'to' => '', 'columns' => [], 'rows' => [['metric' => 'golden_quality_pass', 'kind' => 'quality', 'floor' => $floor, 'direction' => 'up', 'values' => $values, 'flags' => $flags]], 'feedback' => [], 'stop' => false];

    $mock = Mockery::mock(Scorecard::class);

    $mock->shouldReceive('compute')->andReturn($report);

    app()->instance(Scorecard::class, $mock);
}

function answersFor(?string $variant, int $count): void
{
    $conversation = Conversation::query()->create(['user_id' => User::factory()->create()->id, 'tenant_id' => 1, 'status' => 'open', 'prompt_version' => 'x', 'summary_through_seq' => 0]);

    for ($i = 0; $i < $count; $i++) {
        Message::withoutGlobalScopes()->create(['conversation_id' => $conversation->id, 'tenant_id' => 1, 'role' => 'assistant', 'content' => 'a', 'question' => 'q', 'status' => 'completed', 'experiment_variant' => $variant, 'seq' => $i + 1, 'tokens' => 10]);
    }
}

it('reads nothing when no version is in a trial or freshly promoted', function () {
    RegistryWorld::entry('billing', 1, 'production', ['promoted_at' => now()->subDays(40)]);

    $mock = Mockery::mock(Scorecard::class);

    $mock->shouldNotReceive('compute');

    app()->instance(Scorecard::class, $mock);

    expect(app(GuardrailWatch::class)->check('support.reply'))->toBe([]);
});

it('raises one event per flagged variant with enough answers, and skips shadow columns', function () {
    Event::fake([GuardrailBreached::class]);

    RegistryWorld::entry('billing', 1, 'canary', ['percent' => 10]);

    answersFor('treatment', 25);
    answersFor('control', 5);

    scorecardSays(['treatment' => 'below floor', 'control' => 'below floor', 'shadow:billing-v1' => 'below floor'], ['treatment' => 0.71, 'control' => 0.80, 'shadow:billing-v1' => 0.5]);

    $breaches = app(GuardrailWatch::class)->check('support.reply');

    expect($breaches)->toHaveCount(1)
        ->and($breaches[0])->toMatchObject(['feature' => 'support.reply', 'metric' => 'golden_quality_pass', 'value' => 0.71, 'floor' => 0.95, 'variant' => 'treatment']);

    Event::assertDispatched(GuardrailBreached::class, 1);
});

it('stops the canary end to end when the watch finds its guardrail breached', function () {
    RegistryWorld::entry('billing', 1, 'production', ['promoted_at' => now()->subDays(40)]);
    RegistryWorld::entry('billing', 2, 'canary', ['percent' => 10]);

    answersFor('treatment', 30);

    scorecardSays(['treatment' => 'below floor'], ['treatment' => 0.71]);

    $this->artisan('ai:guardrails')->expectsOutputToContain('GuardrailBreached support.reply golden_quality_pass 0.71 < floor 0.95 (treatment)')->assertExitCode(0);

    expect(ModelRegistryEntry::query()->where('version', 2)->value('stage'))->toBe('rejected')
        ->and(Registry::productionFor('support.reply')->version)->toBe(1);
});
