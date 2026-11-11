<?php

use App\Ai\Cost\CallCost;
use App\Ai\Cost\PriceMap;
use App\Ai\Events\ModelCalled;
use App\Ai\Python\Streams;
use App\Jobs\WriteLedgerBatch;
use App\Models\AiLedgerEntry;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeStreams;

// Chapter 36, Topics 2 to 4: one append-only row per call, priced when it is written, labelled from where the call came from, written to Postgres in batches off the request path.
beforeEach(function () {
    spendControlsOn();

    $this->streams = fakeStreams();

    asTenant(12, 94);
});

function ledgerCall(string $feature = 'support.reply', string $provider = 'frontier', string $model = 'frontier-example', array $usage = ['prompt_tokens' => 3_500, 'completion_tokens' => 200, 'cached_tokens' => 2_400]): ModelCalled
{
    $event = new ModelCalled($feature, $provider, $model, $usage, 'stop', 900, conversationId: null, cachedTokens: $usage['cached_tokens'] ?? null, traceId: str_repeat('ab', 16));

    event($event);

    return $event;
}

function consumeLedger(): void
{
    test()->artisan('ai:ledger-consume', ['--once' => true])->assertSuccessful();
}

describe('what goes on the stream', function () {
    it('appends one entry per call, with the five labels and the price version, and writes nothing to the database', function () {
        ledgerCall();

        $entry = json_decode($this->streams->entries['{ai-budget}:ledger'][0]['fields']['entry'], true);

        expect($this->streams->length('{ai-budget}:ledger'))->toBe(1)
            ->and($entry)->toMatchArray([
                'tenant_id' => 12,
                'user_id' => 94,
                'feature' => 'support.reply',
                'team' => 'support',
                'provider' => 'frontier',
                'model' => 'frontier-example',
                'input_tokens' => 3_500,
                'cached_input_tokens' => 2_400,
                'output_tokens' => 200,
                'price_version' => app(PriceMap::class)->version(),
                'cost_micro_usd' => 7_020,
                'kind' => 'call',
                'trace_id' => str_repeat('ab', 16),
            ])
            ->and(AiLedgerEntry::count())->toBe(0);
    });

    it('takes the model and provider that answered, not the one that was asked for', function () {
        ledgerCall(provider: 'local', model: 'llama3.1:8b');

        $entry = json_decode($this->streams->entries['{ai-budget}:ledger'][0]['fields']['entry'], true);

        expect($entry['provider'])->toBe('local')
            ->and($entry['cost_micro_usd'])->toBe(0);
    });

    it('labels work with no tenant as anonymous (null) and an unknown feature as unowned', function () {
        Context::flush();

        ledgerCall(feature: 'experiment.something');

        $entry = json_decode($this->streams->entries['{ai-budget}:ledger'][0]['fields']['entry'], true);

        expect($entry['tenant_id'])->toBeNull()
            ->and($entry['team'])->toBe('unowned');
    });

    it('records a local call with its tokens and no cost, so a feature that moves to local shows its saving', function () {
        ledgerCall(provider: 'local', model: 'llama3.1:8b', usage: ['prompt_tokens' => 1_200, 'completion_tokens' => 80]);

        consumeLedger();

        $row = AiLedgerEntry::sole();

        expect($row->input_tokens)->toBe(1_200)->and($row->cost_micro_usd)->toBe(0);
    });

    it('can charge an amortized hardware rate to local calls', function () {
        config()->set('ai.ledger.hardware_usd_per_million_tokens', ['*' => 0.20]);

        ledgerCall(provider: 'local', model: 'llama3.1:8b', usage: ['prompt_tokens' => 1_000_000 - 100, 'completion_tokens' => 100]);

        consumeLedger();

        $row = AiLedgerEntry::sole();

        expect($row->kind)->toBe('hardware')->and($row->cost_micro_usd)->toBe(200_000);
    });

    it('says why a hosted model nobody priced costs nothing in the ledger', function () {
        ledgerCall(model: 'unlisted-model');

        consumeLedger();

        expect(AiLedgerEntry::sole())->reason->toBe('unpriced model')->cost_micro_usd->toBe(0);
    });

    it('does not record a cache hit or a degraded answer: no model was called', function () {
        ledgerCall(provider: 'degraded', model: 'budget:search', usage: ['prompt_tokens' => 0, 'completion_tokens' => 0]);

        expect($this->streams->length('{ai-budget}:ledger'))->toBe(0);
    });

    it('is off when the ledger is switched off', function () {
        config()->set('ai.ledger.enabled', false);

        ledgerCall();

        expect($this->streams->length('{ai-budget}:ledger'))->toBe(0);
    });

    it('writes the row directly, once, when the stream cannot be reached', function () {
        app()->instance(Streams::class, new class extends FakeStreams
        {
            public function add(string $stream, array $fields): string
            {
                throw new RuntimeException('redis is down');
            }
        });

        Cache::forget('ai.ledger.stream_down');

        ledgerCall();

        ledgerCall();                                                                // the second does not wait for the dead Redis

        expect(AiLedgerEntry::count())->toBe(2)
            ->and(AiLedgerEntry::first()->cost_micro_usd)->toBe(7_020);
    });
});

describe('the consumer and WriteLedgerBatch', function () {
    it('writes the stream to ai_ledger in batches and acknowledges what it wrote', function () {
        foreach (range(1, 5) as $i) {
            ledgerCall();
        }

        expect($this->streams->pending('{ai-budget}:ledger', 'ledger-writers'))->toBe(0);

        $this->artisan('ai:ledger-consume', ['--once' => true, '--batch' => 2])->expectsOutputToContain('Handed 5 ledger entries to the ledger queue.')->assertSuccessful();

        expect(AiLedgerEntry::count())->toBe(5)
            ->and($this->streams->pending('{ai-budget}:ledger', 'ledger-writers'))->toBe(0)
            ->and(AiLedgerEntry::whereNotNull('entry_id')->count())->toBe(5);
    });

    it('goes to the ledger queue, a Horizon supervisor of its own', function () {
        Queue::fake();

        ledgerCall();

        consumeLedger();

        Queue::assertPushedOn('ledger', WriteLedgerBatch::class);

        expect(config('horizon.defaults.ledger.queue'))->toBe(['ledger']);
    });

    it('inserts nothing the second time a batch is written', function () {
        ledgerCall();

        $entries = $this->streams->readGroup('{ai-budget}:ledger', 'ledger-writers', 'a');

        $job = new WriteLedgerBatch(array_column($entries, 'id'), array_map(fn ($e) => json_decode($e['fields']['entry'], true), $entries));

        $job->handle($this->streams);
        $job->handle($this->streams);

        expect(AiLedgerEntry::count())->toBe(1);
    });

    it('loses nothing when the consumer is killed after reading and before writing', function () {
        foreach (range(1, 3) as $i) {
            ledgerCall();
        }

        $read = $this->streams->readGroup('{ai-budget}:ledger', 'ledger-writers', (string) gethostname(), 10);   // the killed run: read, never written

        expect($read)->toHaveCount(3)
            ->and(AiLedgerEntry::count())->toBe(0)
            ->and($this->streams->pending('{ai-budget}:ledger', 'ledger-writers'))->toBe(3);

        consumeLedger();                                                              // the restart reads its own pending entries first

        expect(AiLedgerEntry::count())->toBe(3)
            ->and($this->streams->pending('{ai-budget}:ledger', 'ledger-writers'))->toBe(0);
    });

    it('waits on the stream while the consumer is down and writes it all when it comes back', function () {
        foreach (range(1, 4) as $i) {
            ledgerCall();
        }

        expect($this->streams->length('{ai-budget}:ledger'))->toBe(4)
            ->and(AiLedgerEntry::count())->toBe(0);

        consumeLedger();

        expect(AiLedgerEntry::count())->toBe(4);
    });
});

describe('the ledger and model_calls', function () {
    it('adds up, over a day, to CallCost over that day\'s model_calls', function () {
        config()->set('ai-pricing.models.frontier-example', ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75, 'per' => 1_000_000, 'source' => 'test']);

        $usages = [
            ['prompt_tokens' => 3_500, 'completion_tokens' => 200, 'cached_tokens' => 2_400],
            ['prompt_tokens' => 12_000, 'completion_tokens' => 150, 'cached_tokens' => 0],
            ['prompt_tokens' => 6_800, 'completion_tokens' => 60, 'cached_tokens' => 6_000, 'cache_write_tokens' => 400],
            ['prompt_tokens' => 800, 'completion_tokens' => 1_500],
        ];

        foreach ($usages as $usage) {
            ledgerCall(usage: $usage);

            ledgerCall(provider: 'local', model: 'llama3.1:8b', usage: ['prompt_tokens' => 1_000, 'completion_tokens' => 50]);
        }

        consumeLedger();

        $price = app(PriceMap::class);

        $recomputed = ModelCall::all()->sum(fn (ModelCall $call) => CallCost::microUsd($call, $price->for($call->provider, $call->model)));

        expect(ModelCall::count())->toBe(8)
            ->and(ledgerTotal())->toBe($recomputed)
            ->and(ledgerTotal())->toBe((int) ModelCall::sum('cost_micros'));
    });

    it('joins each row to its model_calls row and its trace', function () {
        $event = ledgerCall();

        consumeLedger();

        $row = AiLedgerEntry::sole();

        expect($row->model_call_id)->toBe($event->modelCallId)
            ->and($row->trace_id)->toBe(str_repeat('ab', 16));
    });

    it('is append-only: a correction is a new row with a negative amount and a reason', function () {
        ledgerCall();

        consumeLedger();

        AiLedgerEntry::create(['occurred_at' => now(), 'feature' => 'support.reply', 'team' => 'support', 'provider' => 'frontier', 'model' => 'frontier-example', 'input_tokens' => 0, 'cached_input_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 0, 'price_version' => 'v1', 'cost_micro_usd' => -1_000, 'kind' => 'adjustment', 'reason' => 'provider credit, invoice 4471']);

        expect(ledgerTotal())->toBe(7_020 - 1_000)
            ->and(AiLedgerEntry::where('kind', 'adjustment')->sole()->model_call_id)->toBeNull();
    });
});
