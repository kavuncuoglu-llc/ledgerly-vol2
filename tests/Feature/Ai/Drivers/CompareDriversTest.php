<?php

use App\Evals\GoldenSet;
use App\Evals\RoleComparison\FirstTokenProbe;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\File;

// Chapter 47, Topic 9: eval:compare --drivers runs the same golden cases through the same role reached by each driver, one column per driver.
beforeEach(function () {
    config(['ai.eval.fixture_customers' => PHP_INT_MAX, 'ai.eval.enabled' => true]);

    $customer = Customer::factory()->create(['name' => 'Acme Studios']);

    User::factory()->owner($customer)->create();

    $this->dir = sys_get_temp_dir().'/drivers-compare-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    $cases = collect(['a', 'b'])->map(fn ($id) => ['id' => "support-{$id}", 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => "Why was INV-{$id} charged a fee?", 'expected_behavior' => 'answer', 'must_include' => ['1.5%']]);

    File::put($this->dir.'/cases.jsonl', $cases->map(fn ($c) => json_encode($c))->implode("\n")."\n");

    app()->instance(GoldenSet::class, new GoldenSet($this->dir));

    File::deleteDirectory(storage_path('eval'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::deleteDirectory(storage_path('eval'));
});

it('puts one column per driver and keeps only the metrics asked for', function () {
    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    Ai::fake()->for('compare.ttft')->respondToEverythingWith('ready');

    $this->artisan('eval:compare', ['--drivers' => 'raw,prism,ai-sdk', '--with' => 'gates', '--metrics' => 'pass,input_tokens,ttft,p50'])
        ->expectsOutputToContain('golden:billing · 2 cases · 3 roles')
        ->expectsOutputToContain('Golden pass rate')
        ->expectsOutputToContain('Time to first token, p50')
        ->doesntExpectOutputToContain('Required facts present')
        ->assertExitCode(0);

    $saved = json_decode(File::get(storage_path('eval/last-compare.json')), true);

    expect(array_column($saved['results'], 'role'))->toBe(['raw', 'prism', 'ai-sdk'])
        ->and(array_column($saved['results'], 'provider'))->toBe(['local', 'local-prism', 'local-ai-sdk'])
        ->and($saved['results'][1]['metrics'])->toHaveKeys(['golden_pass_rate', 'input_tokens_per_answer', 'ttft_p50_s']);
});

it('refuses a driver or a metric it does not know', function () {
    $this->artisan('eval:compare', ['--drivers' => 'raw,neuron'])
        ->expectsOutputToContain('[neuron] is not a driver')
        ->assertExitCode(1);

    $this->artisan('eval:compare', ['--roles' => 'local', '--metrics' => 'vibes'])
        ->expectsOutputToContain('[vibes] is not a metric')
        ->assertExitCode(1);
});

it('measures the time to first token as the median of the probes after the first', function () {
    Ai::fake()->for('compare.ttft')->respondToEverythingWith('ready');

    $seconds = app(FirstTokenProbe::class)->medianSeconds('local', 3);

    expect($seconds)->toBeFloat()->toBeGreaterThanOrEqual(0.0)->toBeLessThan(5.0);
});

it('says nothing when a probe fails', function () {
    config(['ai.providers.local.base_url' => null]);

    expect(app(FirstTokenProbe::class)->medianSeconds('local', 1))->toBeNull();
});
