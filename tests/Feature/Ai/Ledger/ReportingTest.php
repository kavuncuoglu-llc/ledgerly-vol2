<?php

use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\KillSwitch;
use App\Ai\Budgets\SpendBreaker;
use App\Ai\Ledger\CounterRebuilder;
use App\Mail\SpendShowback;
use App\Models\AiLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;

// Chapter 36, Topics 1, 3 and 9: live spend by any label, a weekly showback per team, and the nightly reconciliation against the provider's own figures.
beforeEach(function () {
    spendControlsOn();

    $this->now = CarbonImmutable::parse('2026-11-11 14:30:00');

    $this->travelTo($this->now);
});

function entry(array $override = []): AiLedgerEntry
{
    return AiLedgerEntry::create($override + ['occurred_at' => now()->subMinutes(10), 'feature' => 'support.reply', 'team' => 'support', 'provider' => 'frontier', 'model' => 'frontier-example', 'tenant_id' => 12, 'user_id' => 94, 'input_tokens' => 4_000, 'cached_input_tokens' => 2_000, 'cache_write_tokens' => 0, 'output_tokens' => 200, 'price_version' => 'v1', 'cost_micro_usd' => 15_000, 'kind' => 'call']);
}

describe('ai:spend', function () {
    it('splits live spend by any labels, with tokens and cost', function () {
        entry();
        entry(['tenant_id' => 40, 'feature' => 'ticket.triage', 'cost_micro_usd' => 40_000, 'input_tokens' => 1_500_000]);
        entry(['tenant_id' => null, 'feature' => 'widget.ask', 'provider' => 'local', 'cost_micro_usd' => 0]);

        [$code, $output] = artisanOutput('ai:spend', ['--by' => 'tenant,feature', '--since' => '1h']);

        expect($code)->toBe(0)
            ->and($output)->toContain('tenant')->toContain('feature')->toContain('anonymous')->toContain('widget.ask')
            ->and($output)->toContain('1.50M / 200')
            ->and($output)->toContain('$0.04')
            ->and($output)->toContain('app hourly ceiling $100 (0%)');
    });

    it('shows the breaker state beside the scope that tripped and the kill switches that are on', function () {
        entry(['feature' => 'ticket.triage', 'cost_micro_usd' => 3_000_000]);

        app(SpendBreaker::class)->evaluate($this->now);

        app(KillSwitch::class)->activate('provider:frontier', 'drill');

        [, $output] = artisanOutput('ai:spend', ['--by' => 'tenant,feature']);

        expect($output)->toContain('breaker OPEN since 14:30')
            ->and($output)->toContain('kill switch ON for provider:frontier');
    });

    it('reads the app hour from the counters', function () {
        $hour = app(BudgetGuard::class)->appHour();

        spendCounters()->set($hour->key, '8000000');

        [, $output] = artisanOutput('ai:spend');

        expect($output)->toContain('app hourly ceiling $100 (8%)');
    });

    it('rejects a label it does not know', function () {
        $this->artisan('ai:spend', ['--by' => 'colour'])->assertFailed();

        $this->artisan('ai:spend', ['--since' => 'lately'])->assertFailed();
    });

    it('reports the stream backlog', function () {
        $streams = fakeStreams();

        $streams->add('{ai-budget}:ledger', ['entry' => '{}']);

        [, $output] = artisanOutput('ai:spend');

        expect($output)->toContain('ledger stream  1 entries');
    });
});

describe('ai:showback', function () {
    it('totals a week by team and feature', function () {
        entry();
        entry(['feature' => 'reminder.draft', 'team' => 'billing', 'cost_micro_usd' => 4_000]);
        entry(['occurred_at' => now()->subDays(20)]);

        [, $output] = artisanOutput('ai:showback');

        expect($output)->toContain('support')->toContain('billing')->toContain('reminder.draft')->toContain('total $0.02');
    });

    it('mails each team only its own lines', function () {
        Mail::fake();

        config()->set('ai.teams.support.email', ['support@ledgerly.test']);
        config()->set('ai.teams.billing.email', ['billing@ledgerly.test']);

        entry();
        entry(['feature' => 'reminder.draft', 'team' => 'billing', 'cost_micro_usd' => 4_000]);

        $this->artisan('ai:showback', ['--send' => true])->assertSuccessful();

        Mail::assertSent(SpendShowback::class, 2);
        Mail::assertSent(SpendShowback::class, fn ($mail) => $mail->hasTo('billing@ledgerly.test') && $mail->team === 'billing' && count($mail->rows) === 1 && $mail->rows[0]['feature'] === 'reminder.draft');
    });

    it('is scheduled on Mondays', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'ai:showback'));

        expect($event->expression)->toBe('0 7 * * 1');
    });

    it('says so when there is nothing to report', function () {
        $this->artisan('ai:showback')->expectsOutputToContain('No spend in the window.')->assertSuccessful();
    });
});

describe('ai:reconcile', function () {
    function usageFile(array $rows): string
    {
        $path = sys_get_temp_dir().'/usage-'.bin2hex(random_bytes(4)).'.json';

        file_put_contents($path, json_encode($rows));

        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }

    beforeEach(function () {
        fakeStreams();

        entry(['occurred_at' => CarbonImmutable::parse('2026-11-10 10:00:00'), 'input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'cost_micro_usd' => 4_500_000]);
    });

    it('agrees when the provider\'s figures match the ledger', function () {
        $file = usageFile([['date' => '2026-11-10', 'provider' => 'frontier', 'model' => 'frontier-example', 'input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'cost_usd' => 4.5]]);

        $this->artisan('ai:reconcile', ['--usage' => $file, '--no-rebuild' => true])->expectsOutputToContain('✓ agrees')->assertSuccessful();
    });

    it('fails on a gap and says it is a price when the tokens agree', function () {
        $file = usageFile([['date' => '2026-11-10', 'provider' => 'frontier', 'model' => 'frontier-example', 'input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'cost_usd' => 6.0]]);

        $this->artisan('ai:reconcile', ['--usage' => $file, '--no-rebuild' => true])->expectsOutputToContain('a price in config/ai-pricing.php is wrong')->assertFailed();
    });

    it('says unrecorded calls when the provider has more tokens', function () {
        $file = usageFile([['date' => '2026-11-10', 'provider' => 'frontier', 'model' => 'frontier-example', 'input_tokens' => 1_500_000, 'output_tokens' => 100_000, 'cost_usd' => 6.0]]);

        $this->artisan('ai:reconcile', ['--usage' => $file, '--no-rebuild' => true])->expectsOutputToContain('token counts differ by')->assertFailed();
    });

    it('names a model the provider billed and the ledger has never seen', function () {
        $file = usageFile([
            ['date' => '2026-11-10', 'provider' => 'frontier', 'model' => 'frontier-example', 'input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'cost_usd' => 4.5],
            ['date' => '2026-11-10', 'provider' => 'frontier', 'model' => 'frontier-mini', 'input_tokens' => 10, 'output_tokens' => 10, 'cost_usd' => 1.0],
        ]);

        $this->artisan('ai:reconcile', ['--usage' => $file, '--no-rebuild' => true])->expectsOutputToContain('unrecorded calls, or another application uses the key')->assertFailed();
    });

    it('says a provider with no usage source is not reconciled, instead of failing', function () {
        $this->artisan('ai:reconcile', ['--no-rebuild' => true])->expectsOutputToContain('frontier: not reconciled')->assertSuccessful();
    });

    it('rebuilds the running counters from the ledger, which wins', function () {
        asTenant(12);

        entry(['occurred_at' => now()->subMinutes(5), 'cost_micro_usd' => 7_000]);

        $budgets = collect(app(BudgetGuard::class)->budgetsFor('support.reply'))->keyBy('kind');

        spendCounters()->set($budgets['feature.day']->key, '999999');                // the counter drifted

        spendCounters()->set($budgets['tenant.month']->key, '1');

        $set = app(CounterRebuilder::class)->rebuild();

        expect($set)->toBeGreaterThan(0)
            ->and(spendCounters()->value($budgets['feature.day']->key))->toBe(7_000)
            ->and(spendCounters()->value($budgets['tenant.month']->key))->toBe(4_507_000)                // the month includes yesterday's $4.50
            ->and(spendCounters()->value($budgets['app.hour']->key))->toBe(7_000);
    });

    it('is scheduled nightly', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'ai:reconcile'));

        expect($event->expression)->toBe('0 4 * * *');
    });
});

describe('attribution', function () {
    it('puts tenant, user and channel in Context for a signed-in request, and anonymous for a visitor', function () {
        [$user] = customerWithInvoice();

        $this->actingAs($user)->get('/help');

        expect(Context::get('tenant_id'))->toBe($user->tenant_id)
            ->and(Context::get('user_id'))->toBe($user->id)
            ->and(Context::get('channel'))->toBe('app');

        Context::flush();

        auth()->logout();

        $this->get('/widget');

        expect(Context::get('tenant_id'))->toBeNull()
            ->and(Context::get('channel'))->toBe('anonymous');
    });
});
