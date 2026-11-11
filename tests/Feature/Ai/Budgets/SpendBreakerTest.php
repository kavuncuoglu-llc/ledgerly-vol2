<?php

use App\Ai\Budgets\BudgetExhausted;
use App\Ai\Budgets\Ladder;
use App\Ai\Budgets\SpendBreaker;
use App\Ai\Cost\Replay\PostmortemReplay;
use App\Facades\Ai;
use App\Models\AiLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

// Chapter 36, Topic 7: a breaker watches the rate. Over five times the normal for this hour, and above a small floor, the scope drops to its cheapest rung for thirty minutes.
beforeEach(function () {
    spendControlsOn();

    asTenant(7);

    $this->now = CarbonImmutable::parse('2026-11-11 14:30:00');

    $this->travelTo($this->now);
});

function ledgerSpend(string $feature, float $usd, CarbonImmutable $at, ?int $tenant = null, ?string $trace = null): void
{
    AiLedgerEntry::create(['occurred_at' => $at, 'feature' => $feature, 'team' => 'support', 'provider' => 'frontier', 'model' => 'frontier-example', 'tenant_id' => $tenant, 'trace_id' => $trace, 'input_tokens' => 0, 'cached_input_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 0, 'price_version' => 'v1', 'cost_micro_usd' => (int) round($usd * 1_000_000), 'kind' => 'call']);
}

function normalWeek(string $feature, float $usdPerWindow, CarbonImmutable $now, ?int $tenant = null): void
{
    foreach (range(1, 7) as $day) {
        ledgerSpend($feature, $usdPerWindow, $now->subDays($day)->subMinutes(5), $tenant);
    }
}

function breaker(): SpendBreaker
{
    return app(SpendBreaker::class);
}

it('trips on the seeded agent loop, and says which scope', function () {
    app(PostmortemReplay::class)->run('loop', $this->now);

    $report = collect(breaker()->evaluate($this->now))->keyBy('scope');

    expect($report['feature:triage.investigate']['state'])->toBe(SpendBreaker::OPEN)
        ->and($report['feature:triage.investigate']['current'])->toBeGreaterThan(10_000_000)
        ->and($report['feature:support.reply']['state'])->toBe(SpendBreaker::CLOSED);
});

it('trips within two minutes of the loop starting', function () {
    $start = $this->now->subMinutes(14);

    app(PostmortemReplay::class)->run('loop', $this->now);

    $tripped = null;

    foreach (range(1, 14) as $minute) {
        $report = collect(breaker()->evaluate($start->addMinutes($minute)))->keyBy('scope');

        if (($report['feature:triage.investigate']['state'] ?? null) === SpendBreaker::OPEN) {
            $tripped = $minute;

            break;
        }
    }

    expect($tripped)->toBeLessThanOrEqual(2);                                      // about $0.93 a minute against a floor of $0.50 and a normal of nothing
});

it('does not trip below the absolute floor, however many times over normal', function () {
    ledgerSpend('quiet.feature', 0.11, $this->now->subMinutes(3));                // two cents to eleven cents

    normalWeek('quiet.feature', 0.02, $this->now);

    expect(collect(breaker()->evaluate($this->now))->firstWhere('scope', 'feature:quiet.feature')['state'])->toBe(SpendBreaker::CLOSED);
});

it('does not trip on a busy hour that is within five times its normal', function () {
    normalWeek('support.reply', 1.00, $this->now);

    ledgerSpend('support.reply', 4.50, $this->now->subMinutes(4));

    expect(collect(breaker()->evaluate($this->now))->firstWhere('scope', 'feature:support.reply')['state'])->toBe(SpendBreaker::CLOSED);
});

it('trips at more than five times the normal for this hour, using the median of the week', function () {
    normalWeek('support.reply', 1.00, $this->now);

    ledgerSpend('support.reply', 5.20, $this->now->subMinutes(4));

    $row = collect(breaker()->evaluate($this->now))->firstWhere('scope', 'feature:support.reply');

    expect($row['state'])->toBe(SpendBreaker::OPEN)
        ->and($row['baseline'])->toBe(1_000_000.0)
        ->and(round($row['ratio'], 1))->toBe(5.2);
});

it('also watches each tenant', function () {
    normalWeek('support.reply', 0.20, $this->now, tenant: 12);

    ledgerSpend('support.reply', 3.00, $this->now->subMinutes(2), tenant: 12);

    $report = collect(breaker()->evaluate($this->now))->keyBy('scope');

    expect($report['tenant:12']['state'])->toBe(SpendBreaker::OPEN);
});

it('sends an open scope to its cheapest rung: a hosted call is refused, a local one is not', function () {
    ledgerSpend('support.reply', 5.00, $this->now->subMinutes(2));

    breaker()->evaluate($this->now);

    Ai::fake()->for('support.reply')->respondToEverythingWith('ok');

    try {
        Ai::chat('frontier')->chat(callCosting(1_500));
    } catch (BudgetExhausted $e) {
        expect($e->budget->kind)->toBe('breaker')
            ->and($e->rung)->toBe(Ladder::CHEAPER_ROLE);                           // for a customer of that feature: a local answer, not an error

        expect(Ai::chat('local')->chat(callCosting(1_500))->content)->toBe('ok');

        return;
    }

    $this->fail('The open breaker did not refuse the call.');
});

it('tells the owning team with the top traces of the window', function () {
    config()->set('ai.budgets.notify.channel_webhook', 'https://chat.example.com/hooks/ai');

    Http::fake(['chat.example.com/*' => Http::response('ok')]);

    ledgerSpend('support.reply', 3.00, $this->now->subMinutes(2), trace: 'aaaa1111bbbb2222cccc3333dddd4444');

    ledgerSpend('support.reply', 0.60, $this->now->subMinutes(3), trace: 'eeee5555ffff6666aaaa7777bbbb8888');

    breaker()->evaluate($this->now);

    Http::assertSent(fn ($request) => str_contains($request['text'], 'feature:support.reply') && str_contains($request['text'], 'aaaa1111bbbb2222cccc3333dddd4444') && str_contains($request['text'], 'for 30 minutes'));
});

it('opens only once, however often it runs', function () {
    config()->set('ai.budgets.notify.channel_webhook', 'https://chat.example.com/hooks/ai');

    Http::fake(['chat.example.com/*' => Http::response('ok')]);

    ledgerSpend('support.reply', 3.00, $this->now->subMinutes(2));

    breaker()->evaluate($this->now);

    breaker()->evaluate($this->now->addMinute());

    breaker()->evaluate($this->now->addMinutes(2));

    Http::assertSentCount(1);
});

it('lets a trickle through when half open, and closes after a quiet window', function () {
    ledgerSpend('support.reply', 3.00, $this->now->subMinutes(2));

    breaker()->evaluate($this->now);

    $later = $this->now->addMinutes(31);                                              // thirty minutes on: the spike has left the window, the breaker is due

    $half = collect(breaker()->evaluate($later))->firstWhere('scope', 'feature:support.reply');

    expect($half['state'])->toBe(SpendBreaker::HALF_OPEN);

    $allowed = 0;

    foreach (range(1, 20) as $call) {
        if (breaker()->blocks('support.reply', null) === null) {
            $allowed++;
        }
    }

    expect($allowed)->toBe(2);                                                        // one in ten

    $closed = collect(breaker()->evaluate($later->addMinute()))->firstWhere('scope', 'feature:support.reply');

    expect($closed['state'])->toBe(SpendBreaker::CLOSED)
        ->and(breaker()->blocks('support.reply', null))->toBeNull();
});

it('opens again when the trickle was still spending like the loop', function () {
    ledgerSpend('support.reply', 3.00, $this->now->subMinutes(2));

    breaker()->evaluate($this->now);

    $later = $this->now->addMinutes(31);

    breaker()->evaluate($later);

    ledgerSpend('support.reply', 4.00, $later);

    expect(collect(breaker()->evaluate($later->addSeconds(30)))->firstWhere('scope', 'feature:support.reply')['state'])->toBe(SpendBreaker::OPEN);
});

it('is run by ai:spend-breaker, which prints what moved', function () {
    ledgerSpend('support.reply', 3.00, $this->now->subMinutes(2));

    $this->artisan('ai:spend-breaker')->expectsOutputToContain('open (changed now)')->assertSuccessful();

    $this->artisan('ai:spend-breaker')->assertSuccessful();
});

it('says so when there is nothing to watch', function () {
    $this->artisan('ai:spend-breaker')->expectsOutputToContain('No spend in the window.')->assertSuccessful();
});

it('is scheduled every minute', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'ai:spend-breaker'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
