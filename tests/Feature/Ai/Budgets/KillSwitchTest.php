<?php

use App\Ai\Budgets\BudgetExhausted;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Budgets\Ladder;
use App\Ai\Events\EmergencyKillSwitchActivated;
use App\Facades\Ai;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// Chapter 36, Topic 7: an immediate stop of all spend on a provider, a feature or the whole app. It never resets itself.
beforeEach(function () {
    spendControlsOn();

    asTenant(7);

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');
});

it('stops every call on the app, and the exception says to show the bottom rung', function () {
    app(KillSwitch::class)->activate('global', 'spend 40x baseline, see trace 4bf92f35');

    try {
        Ai::chat('frontier')->chat(callCosting(1_500));
    } catch (BudgetExhausted $e) {
        expect($e->isKillSwitch())->toBeTrue()
            ->and($e->rung)->toBe(Ladder::BOTTOM)
            ->and($e->getMessage())->toContain('kill switch [global]');

        return;
    }

    $this->fail('The kill switch did not stop the call.');
});

it('stops a provider and leaves the others alone', function () {
    app(KillSwitch::class)->activate('provider:frontier', 'runaway');

    expect(fn () => Ai::chat('frontier')->chat(callCosting(1_500)))->toThrow(BudgetExhausted::class)
        ->and(Ai::chat('local')->chat(callCosting(1_500))->content)->toBe('ok');
});

it('stops a feature and leaves the others alone', function () {
    app(KillSwitch::class)->activate('feature:triage.draft', 'runaway');

    expect(fn () => Ai::chat('local')->chat(callCosting(1_500, 'triage.draft')))->toThrow(BudgetExhausted::class)
        ->and(Ai::chat('local')->chat(callCosting(1_500, 'support.reply'))->content)->toBe('ok');
});

it('stays on until a person resets it, with a reason', function () {
    $kill = app(KillSwitch::class);

    $kill->activate('provider:frontier', 'runaway');

    $this->travel(3)->days();

    expect($kill->isActive('support.reply', 'frontier'))->toBeTrue();

    expect($kill->reset('provider:frontier', 'InvestigateAgent round limit restored in v7.1'))->toBeTrue()
        ->and($kill->isActive('support.reply', 'frontier'))->toBeFalse()
        ->and($kill->reset('provider:frontier', 'again'))->toBeFalse();
});

it('refuses a scope it does not know', function () {
    expect(fn () => app(KillSwitch::class)->activate('everything', 'x'))->toThrow(InvalidArgumentException::class);
});

it('raises EmergencyKillSwitchActivated and pages on-call and the team channel', function () {
    config()->set('ai.budgets.notify', ['oncall_email' => 'oncall@ledgerly.test', 'channel_webhook' => 'https://chat.example.com/hooks/ai']);

    Http::fake(['chat.example.com/*' => Http::response('ok')]);

    app(KillSwitch::class)->activate('provider:frontier', 'spend 40x baseline');

    expect(collect(Mail::mailer()->getSymfonyTransport()->messages())->flatMap(fn ($m) => collect($m->getEnvelope()->getRecipients())->map->getAddress())->all())->toContain('oncall@ledgerly.test');

    Http::assertSent(fn ($request) => $request->url() === 'https://chat.example.com/hooks/ai' && str_contains($request['text'], 'provider:frontier') && str_contains($request['text'], 'spend 40x baseline'));
});

it('dispatches the event with the scope, the reason and who fired it', function () {
    Event::fake([EmergencyKillSwitchActivated::class]);

    $by = User::factory()->support()->create();

    app(KillSwitch::class)->activate('global', 'drill', $by);

    Event::assertDispatched(EmergencyKillSwitchActivated::class, fn ($event) => $event->scope === 'global' && $event->reason === 'drill' && $event->by->is($by));
});

it('fires by itself when the app\'s hourly ceiling is hit', function () {
    Event::fake([EmergencyKillSwitchActivated::class]);

    config()->set('ai.budgets.app_hour_usd', 0.001);                                  // $0.001 an hour: 1,000 micro-dollars

    expect(fn () => Ai::chat('frontier')->chat(callCosting(5_000)))->toThrow(BudgetExhausted::class);

    Event::assertDispatched(EmergencyKillSwitchActivated::class, fn ($event) => $event->scope === 'global' && str_contains($event->reason, 'hourly ceiling'));

    expect(app(KillSwitch::class)->isActive('support.reply', 'local'))->toBeTrue();
});

describe('ai:kill', function () {
    it('fires and resets a switch, and lists the ones that are on', function () {
        $this->artisan('ai:kill', ['--provider' => 'frontier', '--reason' => 'spend 40x baseline'])->expectsOutputToContain('Kill switch ON for [provider:frontier]')->assertSuccessful();

        $this->artisan('ai:kill', ['--list' => true])->expectsOutputToContain('provider:frontier')->assertSuccessful();

        expect(app(KillSwitch::class)->isActive('support.reply', 'frontier'))->toBeTrue();

        $this->artisan('ai:kill', ['--reset' => true, '--provider' => 'frontier', '--reason' => 'limit restored'])->expectsOutputToContain('Kill switch OFF')->assertSuccessful();

        expect(app(KillSwitch::class)->isActive('support.reply', 'frontier'))->toBeFalse();
    });

    it('needs a scope and a reason', function () {
        $this->artisan('ai:kill', ['--reason' => 'x'])->expectsOutputToContain('Name one scope')->assertFailed();

        $this->artisan('ai:kill', ['--app' => true])->expectsOutputToContain('Give a reason')->assertFailed();

        $this->artisan('ai:kill', ['--feature' => 'bad scope!', '--reason' => 'x'])->assertFailed();
    });

    it('says when there is nothing to list', function () {
        $this->artisan('ai:kill', ['--list' => true])->expectsOutputToContain('No kill switch is on.')->assertSuccessful();
    });
});
