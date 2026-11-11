<?php

use App\Ai\Cost\CallCost;
use App\Ai\Cost\ComponentBreakdown;
use App\Models\ModelCall;
use Illuminate\Support\Str;

// Chapter 37, Topics 1 and 9: where each category settles, why answers escalate, what they cost, and where the money inside one answer goes.
function cascadedAnswer(string $category, array $steps, ?int $conversation = null): void
{
    $id = (string) Str::uuid();

    foreach ($steps as $step => [$provider, $micros, $failed, $latency]) {
        ModelCall::create(['feature' => 'support.reply', 'provider' => $provider, 'model' => 'm', 'prompt_tokens' => 100, 'completion_tokens' => 10, 'finish_reason' => 'stop', 'latency_ms' => $latency, 'cost_micros' => $micros, 'conversation_id' => $conversation, 'cascade_id' => $id, 'cascade_step' => $step, 'cascade_failed' => $failed, 'category' => $category]);
    }
}

beforeEach(function () {
    config(['ai.tiers.mid' => 'local-70b', 'ai.cascade.human_cost_per_escalation_usd' => 4.00]);
});

function week(): void
{
    foreach (range(1, 6) as $i) {
        cascadedAnswer('billing', [['local', 0, null, 800]]);
    }

    foreach (range(1, 2) as $i) {
        cascadedAnswer('billing', [['local', 0, 'citations', 800], ['local-70b', 2_000, null, 1_500]]);
    }

    cascadedAnswer('billing', [['local', 0, 'required_fact', 800], ['local-70b', 2_000, 'citations', 1_500], ['frontier', 13_000, null, 2_500]]);

    cascadedAnswer('billing', [['local', 0, 'citations', 800], ['local-70b', 2_000, 'citations', 1_500], ['frontier', 13_000, 'citations', 2_500]]);

    foreach (range(1, 4) as $i) {
        cascadedAnswer('safety', [['frontier', 13_000, null, 2_200]]);
    }
}

it('says where each category settles, why answers escalated, and what a person costs', function () {
    week();

    [$code, $output] = artisanOutput('ai:cascade-report', ['--days' => 7]);

    expect($code)->toBe(0)
        ->and($output)->toMatch('/billing\s+\|\s+local\s+\|\s+60%\s+\|\s+20%\s+\|\s+10%\s+\|\s+10%\s+\|\s+citations/')
        ->and($output)->toMatch('/safety\s+\|\s+frontier\s+\|\s+—\s+\|\s+—\s+\|\s+100%\s+\|\s+0%/')
        ->and($output)->toContain('cost per answer $0.0')
        ->and($output)->toContain('all-frontier: $0.013')
        ->and($output)->toContain('added wait p50 +')
        ->and($output)->toContain('handed to a person × $4.00');
});

it('prices a conversation as the AI spend plus the share handed to a person times their time (Figure 37-8)', function () {
    week();

    [, $output] = artisanOutput('ai:cascade-report');

    expect($output)->toMatch('/total cost per conversation \$0\.\d+ = AI \$0\.\d+ \+ 7% handed to a person × \$4\.00/');
});

it('compares with the week before', function () {
    week();

    $old = ModelCall::query()->get()->each->update(['created_at' => now()->subDays(9)]);

    cascadedAnswer('billing', [['frontier', 13_000, null, 2_000]]);
    cascadedAnswer('billing', [['frontier', 13_000, null, 2_000]]);

    [, $output] = artisanOutput('ai:cascade-report', ['--compare' => 'previous-week']);

    expect($output)->toContain('settled at the cheapest tier')->toContain('→')->toContain('cost per answer');
});

it('lists every attempt of every question in a conversation', function () {
    cascadedAnswer('billing', [['local', 0, 'citations', 800], ['local-70b', 2_000, null, 1_500]], conversation: 77);

    [$code, $output] = artisanOutput('ai:cascade-report', ['--conversation' => 77]);

    expect($code)->toBe(0)
        ->and($output)->toContain('question 1 (billing): settled on mid')->toContain('citations')->toContain('passed');

    $this->artisan('ai:cascade-report', ['--conversation' => 999])->assertFailed();
});

it('says so when there is nothing to report', function () {
    $this->artisan('ai:cascade-report')->expectsOutputToContain('No cascaded answers in the window.')->assertSuccessful();
});

describe('the cost breakdown by component (Figure 37-1)', function () {
    function breakdownCall(array $overrides = []): void
    {
        ModelCall::create($overrides + ['feature' => 'support.reply', 'provider' => 'frontier', 'model' => 'illustrative-frontier', 'prompt_tokens' => 5_000, 'completion_tokens' => 210, 'finish_reason' => 'stop', 'latency_ms' => 1_000, 'cached_input_tokens' => 2_000, 'context_breakdown' => ['system' => 2_400, 'history' => 900, 'summary' => 150, 'context' => 1_500, 'question' => 50]]);
    }

    it('splits a call into the parts of the figure, each at the price that applied', function () {
        breakdownCall();

        $result = app(ComponentBreakdown::class)->of('support.reply', now()->subDay());

        $rows = collect($result['rows'])->keyBy('component');

        expect($rows['System message, cached part']['tokens'])->toBe(2_000)->and($rows['System message, cached part']['micros'])->toBe(600)    // 2,000 at $0.30 per million
            ->and($rows['System message']['tokens'])->toBe(400)->and($rows['System message']['micros'])->toBe(1_200)                            // the rest of it at $3
            ->and($rows['Retrieved sources']['micros'])->toBe(4_500)
            ->and($rows['The answer']['micros'])->toBe(3_150)                                                                                 // 210 at $15
            ->and($result['total_micros'])->toBe(array_sum(array_column($result['rows'], 'micros')));
    });

    it('adds up to what the call cost', function () {
        breakdownCall();

        $price = ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.30, 'cache_write' => 3.75, 'per' => 1_000_000];

        $call = ModelCall::first();

        expect(app(ComponentBreakdown::class)->of('support.reply', now()->subDay())['total_micros'])->toBe(CallCost::microUsd($call, $price));
    });

    it('can price local calls as a hosted model would', function () {
        breakdownCall(['provider' => 'local', 'model' => 'llama3.1:8b']);

        expect(app(ComponentBreakdown::class)->of('support.reply', now()->subDay())['total_micros'])->toBe(0)
            ->and(app(ComponentBreakdown::class)->of('support.reply', now()->subDay(), 'illustrative-frontier')['total_micros'])->toBeGreaterThan(10_000);

        $this->artisan('ai:cost-breakdown', ['--as' => 'illustrative-frontier'])->expectsOutputToContain('Retrieved sources')->assertSuccessful();
    });

    it('names the overhead the provider counted beyond the sections', function () {
        breakdownCall(['prompt_tokens' => 6_000]);

        $rows = collect(app(ComponentBreakdown::class)->of('support.reply', now()->subDay())['rows'])->keyBy('component');

        expect($rows['Tool definitions and overhead']['tokens'])->toBeGreaterThan(900);
    });

    it('says so when no call carries a breakdown', function () {
        $this->artisan('ai:cost-breakdown')->expectsOutputToContain('No priced calls')->assertSuccessful();

        $this->artisan('ai:cost-breakdown', ['--since' => 'soon'])->assertFailed();
    });
});
