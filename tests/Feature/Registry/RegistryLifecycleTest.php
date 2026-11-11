<?php

use App\Ai\Registry\Aliases;
use App\Ai\Registry\ServeFineTune;
use App\Facades\Registry;
use App\Models\ExperimentSetting;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\RegistryWorld;

// Chapter 44, Topics 6 and 7: the stages, the aliases, the experiment behind a trial, promotion against the decision record's criteria, and the way back.
beforeEach(function () {
    RegistryWorld::boot();
});

afterEach(function () {
    RegistryWorld::clean();
});

function canaryWithResults(int $version = 1, array $tuned = [], array $base = []): ModelRegistryEntry
{
    $entry = RegistryWorld::entry('billing', $version, 'canary', ['percent' => 50, 'evaluations' => RegistryWorld::evaluation($tuned, $base)]);

    return $entry;
}

it('moves a version up the ladder one rung at a time and records who and why', function () {
    RegistryWorld::entry();

    Registry::stage('billing', 1, 'shadow', 'maya');
    Registry::stage('billing', 1, 'canary', 'maya', percent: 10);
    $entry = Registry::stage('billing', 1, 'canary', 'maya', percent: 50);

    expect($entry->stage)->toBe('canary')
        ->and($entry->percent)->toBe(50)
        ->and(collect($entry->history)->pluck('stage')->all())->toBe(['candidate', 'shadow', 'canary', 'canary'])
        ->and($entry->history[1])->toMatchArray(['by' => 'maya', 'percent' => 10])
        ->and($entry->history[3]['reason'])->toContain('50%');
});

it('refuses to skip a rung, to try two versions of one name, and to reject without a reason', function () {
    RegistryWorld::entry('billing', 1);
    RegistryWorld::entry('billing', 2);

    expect(fn () => Registry::stage('billing', 1, 'canary', 'maya'))->toThrow(InvalidArgumentException::class, 'canary only from shadow or canary');

    Registry::stage('billing', 1, 'shadow', 'maya');

    expect(fn () => Registry::stage('billing', 2, 'shadow', 'maya'))->toThrow(InvalidArgumentException::class, 'one version of a name is tried at a time')
        ->and(fn () => Registry::stage('billing', 1, 'rejected', 'maya'))->toThrow(InvalidArgumentException::class, 'records why')
        ->and(fn () => Registry::stage('billing', 1, 'production', 'maya'))->toThrow(InvalidArgumentException::class, 'registry:promote')
        ->and(fn () => Registry::stage('billing', 1, 'canary', 'maya', percent: 101))->toThrow(InvalidArgumentException::class, '0 to 100');
});

it('runs a shadow and a canary as one experiment named for the fine-tune, and closes it when the trial ends', function () {
    RegistryWorld::entry();

    Registry::stage('billing', 1, 'shadow', 'maya');

    expect(ExperimentSetting::query()->where('name', 'registry-billing')->first())->toMatchArray(['mode' => 'shadow', 'percent' => 10, 'candidate' => 'registry:local-billing-ft@shadow']);

    Registry::stage('billing', 1, 'canary', 'maya', percent: 25);

    expect(ExperimentSetting::query()->where('name', 'registry-billing')->first())->toMatchArray(['mode' => 'ab', 'percent' => 25, 'candidate' => 'registry:local-billing-ft@canary']);

    Registry::stage('billing', 1, 'candidate', 'maya');

    expect(ExperimentSetting::query()->where('name', 'registry-billing')->first())->toMatchArray(['mode' => 'off', 'percent' => 0]);
});

it('writes the aliases a trial and a promotion need, and config follows them at once', function () {
    RegistryWorld::entry();

    Registry::stage('billing', 1, 'shadow', 'maya');

    expect(Aliases::read())->toBe(['billing@shadow' => ['version' => 1, 'model' => 'ledgerly-billing-ft-v1']])
        ->and(config('ai.providers.local-billing-ft@shadow.model'))->toBe('ledgerly-billing-ft-v1')
        ->and(config('ai.providers.local-billing-ft@shadow.fine_tuned.registry'))->toBe('billing@production');

    Registry::stage('billing', 1, 'canary', 'maya', percent: 10);

    expect(Aliases::read())->toHaveKey('billing@canary')->not->toHaveKey('billing@shadow')
        ->and(config('ai.providers'))->toHaveKey('local-billing-ft@canary')->not->toHaveKey('local-billing-ft@shadow');
});

it('promotes a canary whose stored results meet the decision record, and makes the old production version previous and warm', function () {
    $old = RegistryWorld::entry('billing', 1, 'production', ['promoted_at' => now()->subDays(20)]);
    $new = canaryWithResults(2);

    Registry::stage('billing', 2, 'canary', 'maya', percent: 50);

    $promoted = Registry::promote('billing', 2, 'maya');

    expect($promoted->stage)->toBe('production')
        ->and($promoted->promoted_at)->not->toBeNull()
        ->and($promoted->history[count($promoted->history) - 1]['reason'])->toContain('criteria met')
        ->and($old->refresh()->stage)->toBe('previous')
        ->and($old->warm_until->isFuture())->toBeTrue()
        ->and(Aliases::read())->toBe(['billing@previous' => ['version' => 1, 'model' => 'ledgerly-billing-ft-v1'], 'billing@production' => ['version' => 2, 'model' => 'ledgerly-billing-ft-v2']])
        ->and(config('ai.providers.local-billing-ft.model'))->toBe('ledgerly-billing-ft-v2')
        ->and(Registry::resolve('billing@production'))->toBe('ledgerly-billing-ft-v2')
        ->and(Registry::productionFor('support.reply')->version)->toBe(2)
        ->and(ExperimentSetting::query()->where('name', 'registry-billing')->value('mode'))->toBe('off');
});

it('refuses a promotion the stored results do not support, naming each criterion', function (array $tuned, array $base, string $fails) {
    canaryWithResults(1, $tuned, $base);

    expect(fn () => Registry::promote('billing', 1, 'maya'))->toThrow(InvalidArgumentException::class, $fails)
        ->and(ModelRegistryEntry::query()->first()->stage)->toBe('canary');
})->with([
    'pass rate under the bar' => [['golden_pass_rate' => 0.94], [], 'golden_pass_rate: 0.94 is below 0.96'],
    'a never-gate failed' => [['never_failures' => 1], [], 'never_failures: 1 is above 0'],
    'injection worse than the base' => [['injection_model_success_rate' => 0.2], [], 'injection_model_success_rate: 0.2 is above local\'s 0.06'],
    'tripwire dropped' => [['tripwire_all_largest_drop' => 3.4], [], 'tripwire_all_largest_drop: 3.4 is above 2'],
    'prompt not shorter' => [['input_tokens_per_answer' => 1400], [], 'input_tokens_per_answer: 1400 is more than 70% of local\'s 1500'],
    'a result missing' => [['golden_pass_rate' => null], [], 'golden_pass_rate: no result stored'],
]);

it('refuses a version nobody compared at all, and a version that is not in a canary', function () {
    RegistryWorld::entry('billing', 1, 'canary');
    RegistryWorld::entry('billing', 2, 'candidate');

    expect(fn () => Registry::promote('billing', 1, 'maya'))->toThrow(InvalidArgumentException::class, 'no stored comparison measured the role local-billing-ft')
        ->and(fn () => Registry::promote('billing', 2, 'maya'))->toThrow(InvalidArgumentException::class, 'is promoted from a canary');
});

it('promotes with --force, and the history says what was skipped', function () {
    RegistryWorld::entry('billing', 2, 'rejected');

    $entry = Registry::promote('billing', 2, 'maya', force: true, reason: 'drill');

    expect($entry->stage)->toBe('production')
        ->and(collect($entry->history)->last()['reason'])->toContain('drill')->toContain('forced, criteria skipped');
});

it('rolls back in one step: the alias moves, the flawed version is rejected with the reason, and the page goes to the team', function () {
    $v1 = RegistryWorld::entry('billing', 1, 'production');
    $v2 = canaryWithResults(2);
    Registry::promote('billing', 2, 'maya');

    $back = Registry::rollback('billing', 'required facts below floor on disputes', 'maya');

    expect($back->version)->toBe(1)
        ->and($back->stage)->toBe('production')
        ->and($v2->refresh()->stage)->toBe('rejected')
        ->and(collect($v2->history)->last()['reason'])->toBe('rolled back: required facts below floor on disputes')
        ->and(collect($back->history)->last()['reason'])->toContain('rolled back from v2')
        ->and(Aliases::read())->toBe(['billing@production' => ['version' => 1, 'model' => 'ledgerly-billing-ft-v1']])
        ->and(config('ai.providers.local-billing-ft.model'))->toBe('ledgerly-billing-ft-v1');
});

it('refuses a rollback with nothing to go back to', function () {
    RegistryWorld::entry('billing', 1, 'production');

    expect(fn () => Registry::rollback('billing', 'drill', 'maya'))->toThrow(InvalidArgumentException::class, 'no previous version kept warm')
        ->and(fn () => Registry::rollback('support', 'drill', 'maya'))->toThrow(InvalidArgumentException::class, 'no production version of support');
});

it('reloads a retired version before it goes back to it, which is why that rollback is slow', function () {
    RegistryWorld::entry('billing', 1, 'retired');
    RegistryWorld::entry('billing', 2, 'production');

    $serve = Mockery::mock(ServeFineTune::class);

    $serve->shouldReceive('reload')->once()->withArgs(fn (ModelRegistryEntry $entry) => $entry->version === 1);

    app()->instance(ServeFineTune::class, $serve);

    expect(Registry::rollback('billing', 'drill', 'maya', to: 1)->stage)->toBe('production');
});

it('retires a previous version once its warm period is over: unloaded, archived, and out of the aliases', function () {
    $previous = RegistryWorld::entry('billing', 1, 'previous', ['warm_until' => now()->subMinute()]);
    RegistryWorld::entry('billing', 2, 'production');
    RegistryWorld::entry('billing', 3, 'previous', ['model' => 'other', 'warm_until' => now()->addDay()]);

    $serve = Mockery::mock(ServeFineTune::class);

    $serve->shouldReceive('unload')->once();

    app()->instance(ServeFineTune::class, $serve);

    $retired = Registry::retireExpired();

    expect($retired)->toHaveCount(1)
        ->and($previous->refresh()->stage)->toBe('retired')
        ->and($previous->archived_at)->not->toBeNull()
        ->and(file_exists(RegistryWorld::$directory.'/archive/billing/v1/billing-v1.Q4_K_M.gguf'))->toBeTrue()
        ->and(collect($previous->history)->last()['reason'])->toContain('warm period ended')
        ->and(Aliases::read())->not->toHaveKey('billing@previous.version', 1);
});

it('retires the older previous version when a newer one is promoted over its successor', function () {
    $oldest = RegistryWorld::entry('billing', 1, 'previous', ['warm_until' => now()->addDay()]);
    RegistryWorld::entry('billing', 2, 'production');
    canaryWithResults(3);

    $serve = Mockery::mock(ServeFineTune::class);

    $serve->shouldReceive('unload')->once();

    app()->instance(ServeFineTune::class, $serve);

    Registry::promote('billing', 3, 'maya');

    expect($oldest->refresh()->stage)->toBe('retired')
        ->and(ModelRegistryEntry::query()->where('version', 2)->value('stage'))->toBe('previous');
});

it('stops a trial with the reason, leaving production alone', function () {
    RegistryWorld::entry('billing', 1, 'production');
    RegistryWorld::entry('billing', 2, 'canary', ['percent' => 10]);

    $stopped = Registry::stopTrial('billing', 'required_facts 0.71 < floor 0.95: canary stopped by its guardrail', 'guardrail');

    expect($stopped->stage)->toBe('rejected')
        ->and(ModelRegistryEntry::query()->where('version', 1)->value('stage'))->toBe('production')
        ->and(Registry::stopTrial('billing', 'again', 'guardrail'))->toBeNull();
});

it('prints the history of every version with who and why', function () {
    RegistryWorld::entry();
    Registry::stage('billing', 1, 'shadow', 'maya');

    expect(Artisan::call('registry:history', ['name' => 'billing']))->toBe(0)
        ->and(Artisan::output())->toContain('billing v1 · shadow')->toContain('candidate  test')->toContain('shadow     maya');

    $this->artisan('registry:history', ['name' => 'nothing'])->assertExitCode(1);
});

it('drives the ladder, the promotion and the way back through its commands', function () {
    RegistryWorld::entry('billing', 1, 'production');
    RegistryWorld::entry('billing', 2, 'candidate', ['evaluations' => RegistryWorld::evaluation()]);

    $this->artisan('registry:stage', ['name' => 'billing', 'version' => 2, '--to' => 'shadow', '--by' => 'maya'])->expectsOutputToContain('billing v2 · shadow · 10%')->assertExitCode(0);
    $this->artisan('registry:stage', ['name' => 'billing', 'version' => 2, '--to' => 'canary', '--percent' => 10, '--by' => 'maya'])->assertExitCode(0);
    $this->artisan('registry:promote', ['name' => 'billing', 'version' => 2, '--by' => 'maya'])->expectsOutputToContain('alias billing@production → v2')->expectsOutputToContain('v1 is previous: kept loaded and warm')->assertExitCode(0);
    $this->artisan('registry:rollback', ['name' => 'billing', '--reason' => 'drill', '--by' => 'maya'])->expectsOutputToContain('alias billing@production → v1')->assertExitCode(0);
    $this->artisan('registry:rollback', ['name' => 'billing', '--by' => 'maya'])->expectsOutputToContain('records why')->assertExitCode(1);
    $this->artisan('registry:stage', ['name' => 'billing', 'version' => 9, '--to' => 'shadow'])->expectsOutputToContain('no version 9')->assertExitCode(1);
});
