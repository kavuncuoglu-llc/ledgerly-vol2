<?php

use App\Ai\Encoding\DataEncoder;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Evals\GoldenSet;
use App\Evals\RoleComparison\AccountDataTokens;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\File;

// Chapter 48, Topic 8: three encodings of the same account data, one column each, with the tokens each one spends.
beforeEach(function () {
    config(['ai.eval.fixture_customers' => PHP_INT_MAX, 'ai.eval.enabled' => true]);

    $this->customer = Customer::factory()->create(['name' => 'Acme Studios']);

    User::factory()->owner($this->customer)->create();

    Invoice::factory()->count(8)->create(['customer_id' => $this->customer->id]);

    $this->dir = sys_get_temp_dir().'/encoders-compare-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    File::put($this->dir.'/cases.jsonl', json_encode(['id' => 'support-a', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'Why was INV-1 charged a fee?', 'expected_behavior' => 'answer', 'must_include' => ['1.5%']])."\n");

    app()->instance(GoldenSet::class, new GoldenSet($this->dir));

    File::deleteDirectory(storage_path('eval'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::deleteDirectory(storage_path('eval'));
});

it('writes one column per encoder with the tokens its account data costs', function () {
    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    $this->artisan('eval:compare', ['--encoders' => 'json,compact-v1,toon-spec', '--with' => 'gates', '--metrics' => 'pass,context_tokens'])
        ->expectsOutputToContain('3 roles')
        ->expectsOutputToContain('Account data, tokens')
        ->assertExitCode(0);

    $saved = json_decode(File::get(storage_path('eval/last-compare.json')), true);

    $tokens = array_column(array_column($saved['results'], 'metrics'), 'context_tokens');

    expect(array_column($saved['results'], 'role'))->toBe(['json', 'compact-v1', 'toon-spec'])
        ->and($tokens[0])->toBeGreaterThan($tokens[2])
        ->and($tokens[1])->toBeGreaterThan(0)
        ->and(config('ai.encoding.default'))->toBe('toon');                  // the run puts the default back
});

it('refuses an encoder it does not know', function () {
    $this->artisan('eval:compare', ['--encoders' => 'json,toonish'])
        ->expectsOutputToContain('[toonish] is not an encoder')
        ->assertExitCode(1);
});

it('measures account data the way the assistant sees it', function () {
    $measure = app(AccountDataTokens::class)->measure('toon');

    $expected = app(DataEncoder::class)->encode(Invoice::query()->forTools()->where('customer_id', $this->customer->id)->latest('issued_at')->limit(25)->get(), new InvoiceSummaryShape, 'toon', 'invoices');

    expect($measure['rows'])->toBe(8)
        ->and($measure['tokens'])->toBeGreaterThan(0)
        ->and($expected)->toContain('invoices[8]{number,status,due,total,late_fee,days_overdue}:');
});
