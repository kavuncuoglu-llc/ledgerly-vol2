<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Health\CircuitBreaker;
use App\Facades\Ai;
use App\Livewire\Pulse\AiHealth;
use App\Livewire\Pulse\AiSpend;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

// Chapter 31, Topic 7: Pulse is the at-a-glance page. The app records tokens, cost and time to first token per feature, and breaker trips per provider; two cards show them.
beforeEach(function () {
    Pulse::startRecording();                                                       // phpunit.xml switches Pulse off for every other test

    DB::table('pulse_aggregates')->delete();
    DB::table('pulse_entries')->delete();
    DB::table('pulse_values')->delete();
});

afterEach(function () {
    Pulse::flush();

    Pulse::stopRecording();
});

function pulseTotals(string $type): array
{
    Pulse::ingest();

    return DB::table('pulse_aggregates')->where('type', $type)->get()->groupBy('key')->map(fn ($rows) => $rows->where('aggregate', 'sum')->sum('value'))->all();
}

it('records tokens, cost and time to first token per feature from the usage log, and nothing about the content', function () {
    Http::fake(['*/chat/completions' => streamReply(['Fees ', 'start.'], usage: ['prompt_tokens' => 600, 'completion_tokens' => 40])]);

    config(['ai.rates.local' => ['*' => ['in' => 1.0, 'out' => 2.0]]]);

    foreach (Ai::chat('local')->stream(ChatRequest::make([['role' => 'user', 'content' => 'SECRET QUESTION']], ['profile' => 'deterministic'], 'support.reply')) as $ignored) {
        // read it to the end
    }

    Pulse::ingest();

    $rows = DB::table('pulse_aggregates')->whereIn('type', ['ai_tokens', 'ai_cost_microusd', 'ai_ttft_ms'])->get();

    expect($rows->where('type', 'ai_tokens')->where('aggregate', 'sum')->first()->value)->toEqual(640)
        ->and($rows->where('type', 'ai_cost_microusd')->where('aggregate', 'sum')->first()->value)->toEqual(680)        // 600 × 1.0 + 40 × 2.0 micro-dollars
        ->and($rows->where('type', 'ai_ttft_ms')->pluck('aggregate')->unique()->sort()->values()->all())->toBe(['avg', 'max'])
        ->and($rows->pluck('key')->unique()->all())->toBe(['support.reply'])
        ->and(json_encode(DB::table('pulse_entries')->get()))->not->toContain('SECRET');
});

it('counts a circuit breaker opening, per provider', function () {
    config(['ai.breaker.threshold' => 3]);

    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $ignored) {
        $breaker->recordFailure('local');
    }

    Pulse::ingest();

    $trips = DB::table('pulse_aggregates')->where('type', 'ai_breaker_trip')->where('aggregate', 'count')->first();

    expect($trips->key)->toBe('local')
        ->and($trips->value)->toEqual(1);
});

it('shows the two cards with what was recorded', function () {
    Pulse::record('ai_tokens', 'support.reply', 1200)->sum();
    Pulse::record('ai_cost_microusd', 'support.reply', 2500)->sum();
    Pulse::record('ai_ttft_ms', 'support.reply', 800)->avg()->max();
    Pulse::record('ai_breaker_trip', 'local')->count();
    Pulse::ingest();

    Livewire::withoutLazyLoading()->test(AiSpend::class)->assertSee('support.reply')->assertSee('1,200')->assertSee('$0.0025');
    Livewire::withoutLazyLoading()->test(AiHealth::class)->assertSee('800 ms')->assertSee('Breaker trips')->assertSee('local');
});

it('shows an empty card as empty', function () {
    Livewire::withoutLazyLoading()->test(AiSpend::class)->assertSee('No results');
});

it('lets staff see the dashboard and nobody else', function () {
    $staff = User::factory()->support()->create();

    [$customerUser] = customerWithInvoice();

    expect(Gate::forUser($staff)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($customerUser)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::allows('viewPulse'))->toBeFalse();

    $this->actingAs($customerUser)->get('/pulse')->assertForbidden();
});

it('puts the two cards on the dashboard beside the built-in ones', function () {
    $view = file_get_contents(resource_path('views/vendor/pulse/dashboard.blade.php'));

    expect($view)->toContain('<livewire:ai-spend')->toContain('<livewire:ai-health')->toContain('<livewire:pulse.slow-queries');
});
