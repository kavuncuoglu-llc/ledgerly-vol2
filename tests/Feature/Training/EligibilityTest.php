<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Tests\Support\TrainingCorpus;

// Chapter 40, Topic 3: nothing that is not eligible is ever copied anywhere. The first query decides.
beforeEach(function () {
    $this->seed();

    TrainingCorpus::build(3);
});

afterEach(fn () => TrainingCorpus::cleanUp());

function trEligibleIds(?string $region = null): array
{
    return Conversation::withoutGlobalScopes()->eligibleForTraining($region)->withPositiveSignal()->pluck('id')->all();
}

it('has training use off for every tenant until its admin turns it on', function () {
    expect(Tenant::query()->where('id', '>', 30)->where('training_use', true)->count())->toBe(0)
        ->and(Tenant::find(35)->training_use)->toBeFalse()
        ->and(Tenant::find(35)->trainingConsentBasis())->toBe('')
        ->and(Tenant::find(1)->trainingConsentBasis())->toBe('tenant opt-in under terms v4');
});

it('only gives conversations of tenants that opted in', function () {
    $tenants = Conversation::withoutGlobalScopes()->whereIn('id', trEligibleIds())->pluck('tenant_id')->unique();

    expect($tenants->every(fn ($id) => Tenant::find($id)->training_use))->toBeTrue()
        ->and(Tenant::find(5)->training_use)->toBeFalse();
});

it('leaves out a user who opted out inside an opted-in tenant', function () {
    $excluded = User::query()->where('training_excluded', true)->first();

    $conversation = Conversation::withoutGlobalScopes()->where('id', '>=', 900000)->first();

    $before = in_array($conversation->id, trEligibleIds(), true);

    Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['user_id' => $excluded->id]);

    expect($before)->toBeTrue()
        ->and(in_array($conversation->id, trEligibleIds(), true))->toBeFalse();
});

it('keeps a residency tenant\'s conversations out of every export except one in its own region', function () {
    $residency = Conversation::withoutGlobalScopes()->whereIn('tenant_id', [7, 14, 21])->where('id', '>=', 900000)->pluck('id')->all();

    expect($residency)->not->toBeEmpty()
        ->and(array_intersect($residency, trEligibleIds()))->toBe([])
        ->and(array_intersect($residency, trEligibleIds('us')))->toBe([])
        ->and(array_intersect($residency, trEligibleIds('eu')))->not->toBe([]);
});

it('wants a thumbs-up or a resolved conversation that did not escalate', function () {
    $down = Message::withoutGlobalScopes()->where('feedback', 'down')->first();

    expect($down)->not->toBeNull()
        ->and(in_array($down->conversation_id, trEligibleIds(), true))->toBeFalse();
});

it('reads the best target first: the agent\'s edit, then the sent reply', function () {
    $edited = Message::withoutGlobalScopes()->whereHas('edit')->first();

    $plain = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->whereDoesntHave('edit')->first();

    expect($edited->targetSource())->toBe('edits')
        ->and($edited->agentEditedReply())->toContain('If anything looks wrong')
        ->and($plain->targetSource())->toBe('production')
        ->and($plain->agentEditedReply())->toBeNull()
        ->and($plain->sentReply())->toBe($plain->content);
});
