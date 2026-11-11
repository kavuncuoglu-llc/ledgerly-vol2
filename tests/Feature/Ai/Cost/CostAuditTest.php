<?php

use App\Ai\Cost\Audit\CostAudit;
use App\Ai\Cost\CallCost;
use App\Ai\Cost\PriceMap;
use App\Ai\Cost\Replay\PostmortemReplay;
use App\Ai\Cost\UnpricedModel;
use App\Ai\Events\ModelCalled;
use App\Models\ModelCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Chapter 35, Topic 9: the audit prices every call, and a seeded replay of each postmortem proves its detector still sees it.
function auditAfter(string $scenario): array
{
    app(PostmortemReplay::class)->run($scenario);

    $report = app(CostAudit::class)->run(CarbonImmutable::now()->subDays(7));

    return [$report, collect($report->signatures)->keyBy('name')];
}

function frontierPrice(): array
{
    return ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75, 'per' => 1_000_000];
}

describe('CallCost', function () {
    it('prices the stable prefix as cache reads and the rest as input (Figure 35-5)', function () {
        $cached = new ModelCall(['prompt_tokens' => 6_800, 'completion_tokens' => 0, 'cached_input_tokens' => 6_000, 'cache_write_tokens' => 0]);
        $uncached = new ModelCall(['prompt_tokens' => 6_800, 'completion_tokens' => 0, 'cached_input_tokens' => 0, 'cache_write_tokens' => 0]);

        expect(CallCost::usd($cached, frontierPrice()))->toEqualWithDelta(0.0042, 0.00001)
            ->and(CallCost::usd($uncached, frontierPrice()))->toEqualWithDelta(0.0204, 0.00001);
    });

    it('prices the answer at the output rate and cache writes at the write rate (Topic 2)', function () {
        $call = new ModelCall(['prompt_tokens' => 3_500, 'completion_tokens' => 200, 'cached_input_tokens' => 0, 'cache_write_tokens' => 1_000]);

        expect(CallCost::usd($call, frontierPrice()))->toEqualWithDelta((2_500 * 3 + 1_000 * 3.75 + 200 * 15) / 1_000_000, 0.0000001);
    });

    it('treats a missing cached count as zero and never prices more cached tokens than there are input tokens', function () {
        $call = new ModelCall(['prompt_tokens' => 100, 'completion_tokens' => 0, 'cached_input_tokens' => null, 'cache_write_tokens' => 0]);

        $impossible = new ModelCall(['prompt_tokens' => 100, 'completion_tokens' => 0, 'cached_input_tokens' => 500, 'cache_write_tokens' => 0]);

        expect(CallCost::usd($call, frontierPrice()))->toEqualWithDelta(0.0003, 0.0000001)
            ->and(CallCost::usd($impossible, frontierPrice()))->toEqualWithDelta(0.00003, 0.0000001);
    });

    it('is the cost RecordModelCall stores, so any call can be recomputed from its row', function () {
        config()->set('ai-pricing.models.priced-model', frontierPrice());

        ModelCalled::dispatch('support.reply', 'frontier', 'priced-model', ['prompt_tokens' => 3_500, 'completion_tokens' => 200, 'cached_tokens' => 2_000, 'cache_write_tokens' => 500], 'stop', 10);

        $row = ModelCall::sole();

        expect($row->cached_input_tokens)->toBe(2_000)
            ->and($row->cache_write_tokens)->toBe(500)
            ->and($row->cost_micros)->toBe(CallCost::microUsd($row, frontierPrice()));
    });
});

describe('the price map', function () {
    it('prices your own hardware at zero and a hosted model by its own entry', function () {
        $prices = app(PriceMap::class);

        config()->set('ai-pricing.models.some-model', frontierPrice());

        expect($prices->for('local', 'llama3.1:8b')['input'])->toBe(0.0)
            ->and($prices->for('frontier', 'some-model')['output'])->toBe(15.00)
            ->and($prices->find('frontier', 'unlisted-model'))->toBeNull();
    });

    it('fails loudly for a model nobody priced instead of counting it as free', function () {
        expect(fn () => app(PriceMap::class)->for('frontier', 'unlisted-model'))->toThrow(UnpricedModel::class);

        DB::table('model_calls')->insert(['feature' => 'support.reply', 'provider' => 'frontier', 'model' => 'unlisted-model', 'prompt_tokens' => 10, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('ai:cost-audit')->expectsOutputToContain('unlisted-model')->assertFailed();
    });

    it('carries the date the prices were checked and a version for the ledger', function () {
        expect(app(PriceMap::class)->version())->not->toBe('unversioned')
            ->and(app(PriceMap::class)->checkedOn())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    });
});

describe('the audit over a week of ordinary traffic', function () {
    it('flags nothing and prices a resolution', function () {
        [$report, $signatures] = auditAfter('healthy');

        expect($report->flagged())->toBe([])
            ->and($report->features['support.reply']['actions'])->toBe(60)
            ->and($report->features['support.reply']['successes'])->toBe(51)
            ->and($report->features['support.reply']['per_success'])->toBeGreaterThan(0.01)->toBeLessThan(0.03);
    });

    it('prices a conversation from its own calls: classification free, two answers at 7,020 micro-dollars', function () {
        [$report] = auditAfter('healthy');

        $spend = 60 * 2 * 7_020 / 1_000_000;

        expect($report->spend)->toEqualWithDelta($spend, 0.0001);
    });
});

describe('a seeded replay of each postmortem', function () {
    it('flags the loop', function () {
        [$report, $signatures] = auditAfter('loop');

        expect($signatures['loop']->flagged)->toBeTrue()
            ->and($signatures['loop']->summary)->toContain('12 conversations')->toContain('worst 41')->toContain('triage.investigate')
            ->and($signatures['loop']->examples[0])->toContain('41 calls')->toContain('trace ')
            ->and($report->tailResolvedShare)->toBe(0.0);
    });

    it('flags the bloated context', function () {
        [, $signatures] = auditAfter('context');

        expect($signatures['context']->flagged)->toBeTrue()
            ->and($signatures['context']->summary)->toContain('52,000')->toContain('budget 6,000')
            ->and($signatures['context']->examples)->not->toBeEmpty();
    });

    it('flags the prefix that stopped caching, and names the release', function () {
        [, $signatures] = auditAfter('cache');

        expect($signatures['cache']->flagged)->toBeTrue()
            ->and($signatures['cache']->summary)->toContain('support.reply cached share')->toContain('floor 60%')->toContain('prompt v8')
            ->and($signatures['cache']->summary)->toContain(CarbonImmutable::now()->subDays(2)->toDateString());
    });

    it('flags the drain: jobs paid for more than once and answers after the customer left', function () {
        [, $signatures] = auditAfter('drain');

        expect($signatures['retries']->flagged)->toBeTrue()
            ->and($signatures['retries']->summary)->toContain('10 answers after 30 minutes')
            ->and($signatures['retries']->examples[0])->toContain('3 attempts');
    });

    it('flags the anonymous flood', function () {
        [, $signatures] = auditAfter('anonymous');

        expect($signatures['anonymous']->flagged)->toBeTrue()
            ->and($signatures['anonymous']->summary)->toContain('alert above 10%')->toContain('400 calls');
    });

    it('leaves the other four detectors alone for the loop, the drain and the flood', function (string $scenario, string $only) {
        [, $signatures] = auditAfter($scenario);

        $flagged = $signatures->filter(fn ($result) => $result->flagged)->keys()->all();

        expect($flagged)->toBe([$only]);
    })->with([
        ['loop', 'loop'],
        ['drain', 'retries'],
        ['anonymous', 'anonymous'],
    ]);

    it('is wiped by --wipe and never touches other rows', function () {
        DB::table('model_calls')->insert(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'llama3.1:8b', 'prompt_tokens' => 10, 'completion_tokens' => 1, 'finish_reason' => 'stop', 'latency_ms' => 1, 'created_at' => now(), 'updated_at' => now()]);

        app(PostmortemReplay::class)->run('loop');

        $this->artisan('ai:cost-replay', ['--wipe' => true])->assertSuccessful();

        expect(ModelCall::count())->toBe(1);
    });

    it('refuses a scenario it does not know', function () {
        $this->artisan('ai:cost-replay', ['scenario' => 'flood'])->expectsOutputToContain('The scenarios are')->assertFailed();
    });
});

describe('ai:cost-audit', function () {
    it('prints the report of Figure 35-9', function () {
        app(PostmortemReplay::class)->run('cache');

        [$code, $output] = artisanOutput('ai:cost-audit', ['--since' => '7d']);

        expect($code)->toBe(0)
            ->and($output)->toMatch('/^spend \$[\d,.]+ · .*\$[\d.]+ per resolution \(support\.reply\)/')
            ->and($output)->toContain('per agreed classification (ticket.classify)')
            ->and($output)->toContain('toxic tail      top 1% of conversations')
            ->and($output)->toContain('✗ cache')
            ->and($output)->toContain('✓ loop')
            ->and($output)->toContain('next            the stable prefix changed');
    });

    it('exits 1 on a flagged signature with --strict, which is how CI uses it', function () {
        app(PostmortemReplay::class)->run('loop');

        $this->artisan('ai:cost-audit', ['--strict' => true])->assertFailed();

        app(PostmortemReplay::class)->run('healthy');

        $this->artisan('ai:cost-audit', ['--strict' => true])->assertSuccessful();
    });

    it('splits cost per resolution by tenant, version and route', function (string $by, string $expected) {
        app(PostmortemReplay::class)->run('cache');

        $this->artisan('ai:cost-audit', ['--by' => $by])->expectsOutputToContain($expected)->assertSuccessful();
    })->with([
        ['tenant', 'tenant '],
        ['version', 'v8'],
        ['route', 'frontier'],
        ['feature', 'support.reply'],
    ]);

    it('prints the report as JSON', function () {
        app(PostmortemReplay::class)->run('healthy');

        [, $output] = artisanOutput('ai:cost-audit', ['--json' => true]);

        $json = json_decode($output, true);

        expect($json['features']['support.reply']['actions'])->toBe(60)
            ->and($json['signatures'])->toHaveCount(5);
    });

    it('shows how a conversation grew, turn by turn', function () {
        app(PostmortemReplay::class)->run('loop');

        $id = (int) DB::table('conversations')->where('title', 'like', 'cost-replay loop')->orderBy('id')->value('id');

        $this->artisan('ai:cost-audit', ['--conversation' => $id])
            ->expectsOutputToContain('turn 41 sent 12,225 input tokens against 3,225 on turn 1')
            ->assertSuccessful();
    });

    it('rejects a window it cannot read', function () {
        $this->artisan('ai:cost-audit', ['--since' => 'yesterday-ish'])->assertFailed();
    });
});
