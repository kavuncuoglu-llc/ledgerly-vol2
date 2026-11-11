<?php

use App\Ai\Budgets\BudgetGuard;
use App\Ai\Budgets\KillSwitch;
use App\Livewire\Pulse\AiBudgets;
use App\Livewire\Pulse\AiSpend;
use App\Models\AiLedgerEntry;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

// Chapter 36, Topic 9 and Chapter 35, Topic 5: the budgets card, and the cached share on the spend card.
beforeEach(function () {
    Pulse::startRecording();
});

afterEach(function () {
    Pulse::flush();

    Pulse::stopRecording();
});

it('shows the app hour against its ceiling, the kill switches and the unattributed share', function () {
    spendControlsOn();

    spendCounters()->set(app(BudgetGuard::class)->appHour()->key, '8000000');

    app(KillSwitch::class)->activate('provider:frontier', 'drill');

    AiLedgerEntry::create(['occurred_at' => now(), 'feature' => 'experiment.x', 'team' => 'unowned', 'provider' => 'frontier', 'model' => 'm', 'input_tokens' => 1, 'cached_input_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 1, 'price_version' => 'v1', 'cost_micro_usd' => 1_000, 'kind' => 'call']);

    Livewire::withoutLazyLoading()->test(AiBudgets::class)
        ->assertSee('$8.00')->assertSee('of $100')->assertSee('(8%)')
        ->assertSee('Kill switch ON')->assertSee('provider:frontier')->assertSee('100%')
        ->assertSee('No spend breaker is open.');
});

it('is on the dashboard', function () {
    expect(file_get_contents(resource_path('views/vendor/pulse/dashboard.blade.php')))->toContain('<livewire:ai-budgets');
});

it('records input and cached input tokens, so the spend card can show a cached share', function () {
    Pulse::record('ai_tokens', 'support.reply', 1200)->sum();
    Pulse::record('ai_cost_microusd', 'support.reply', 2500)->sum();
    Pulse::record('ai_input_tokens', 'support.reply', 1000)->sum();
    Pulse::record('ai_cached_input_tokens', 'support.reply', 600)->sum();
    Pulse::ingest();

    Livewire::withoutLazyLoading()->test(AiSpend::class)->assertSee('Cached')->assertSee('60%');
});
