<?php

use App\Ai\Batch\FakeBatchDriver;
use App\Ai\Conversations\ConversationStore;
use App\Models\AiBatch;
use App\Models\AiBatchItem;
use App\Models\AiLedgerEntry;
use App\Models\HelpChunk;
use App\Models\OnlineJudgment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

// Chapter 37, Topic 7: work nobody is waiting on goes in a batch: request lines with our own id, a state machine, an idempotent collect, failures resubmitted, results in the ledger at the batch price.
beforeEach(function () {
    spendControlsOn();

    config([
        'ai.providers.frontier.batch_driver' => 'fake',
        'ai.batch.discount' => 0.5,
    ]);

    $this->fake = app(FakeBatchDriver::class);

    File::deleteDirectory(storage_path('app/batches'));
});

afterEach(fn () => File::deleteDirectory(storage_path('app/batches')));

function chunksToSynthesize(int $count): void
{
    foreach (range(1, $count) as $i) {
        chunkRow(['chunk_key' => "k{$i}", 'heading' => "Heading {$i}", 'content' => "Heading {$i}\nPassage number {$i} says something specific.", 'kind' => 'parent']);
    }
}

describe('submitting', function () {
    it('writes one JSONL line per request with our own id, records the items and submits', function () {
        chunksToSynthesize(3);

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--from' => 'help-center'])->expectsOutputToContain('3 requests written to batch-')->assertSuccessful();

        $batch = AiBatch::sole();

        $lines = collect(file($batch->input_path, FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

        expect($batch->status)->toBe('submitted')
            ->and($batch->provider_batch_id)->toBe("fake-{$batch->id}")
            ->and($batch->request_count)->toBe(3)
            ->and($lines->pluck('custom_id')->all())->toBe(HelpChunk::orderBy('id')->pluck('id')->map(fn ($id) => "chunk:{$id}")->all())
            ->and($lines[0]['url'])->toBe('/v1/chat/completions')
            ->and($lines[0]['body']['model'])->toBe('frontier-example')
            ->and($lines[0]['body']['messages'][1]['content'])->toContain('Passage number 1')
            ->and(AiBatchItem::count())->toBe(3);
    });

    it('can be limited', function () {
        chunksToSynthesize(5);

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--from' => 'help-center:2'])->assertSuccessful();

        expect(AiBatch::sole()->request_count)->toBe(2);
    });

    it('builds the nightly judge from the golden set\'s reference answers and from the last day\'s replies', function () {
        [$user] = customerWithInvoice();

        $conversation = conversationFor($user);

        $reply = app(ConversationStore::class)->placeholder($conversation, 'How do late fees work?');

        $reply->update(['status' => 'completed', 'content' => 'A fee of 1.5% applies.', 'retrieved' => [['id' => 1, 'eval_label' => 'help:billing/late-fees#how-the-fee-is-calculated']]]);

        $this->artisan('ai:batch:submit', ['name' => 'judge-nightly', '--from' => 'replies:1d'])->assertSuccessful();

        $line = json_decode(file(AiBatch::sole()->input_path, FILE_IGNORE_NEW_LINES)[0], true);

        expect($line['custom_id'])->toBe("reply:{$reply->id}")
            ->and($line['body']['messages'][1]['content'])->toContain('How do late fees work?')->toContain('A fee of 1.5% applies.')->toContain('late-fees#how-the-fee')
            ->and($line['body']['max_tokens'])->toBe(4);

        AiBatch::query()->delete();

        $this->artisan('ai:batch:submit', ['name' => 'judge-nightly', '--from' => 'golden:all'])->assertSuccessful();

        expect(AiBatch::sole()->request_count)->toBeGreaterThan(50);
    });

    it('refuses a kind, a source or a provider it does not know, and an empty batch', function () {
        $this->artisan('ai:batch:submit', ['name' => 'nonsense'])->expectsOutputToContain('No batch kind')->assertFailed();

        $this->artisan('ai:batch:submit', ['name' => 'judge-nightly', '--from' => 'yesterday'])->expectsOutputToContain('golden:all or replies:Nd')->assertFailed();

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--from' => 'help-center'])->expectsOutputToContain('Nothing to batch')->assertFailed();

        chunksToSynthesize(1);

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--provider' => 'frontier-two'])->assertFailed();

        expect(AiBatch::count())->toBe(0);
    });
});

describe('polling and collecting', function () {
    function submitted(int $chunks = 4): AiBatch
    {
        chunksToSynthesize($chunks);

        test()->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--from' => 'help-center'])->assertSuccessful();

        return AiBatch::latest('id')->first();
    }

    it('moves a batch from submitted to completed when the provider says so, and not before', function () {
        $batch = submitted();

        $this->fake->status = 'processing';

        $this->artisan('ai:batch:poll')->expectsOutputToContain('1 submitted, 0 changed state')->assertSuccessful();

        $this->fake->status = 'completed';

        $this->artisan('ai:batch:poll')->expectsOutputToContain('synthesize-help-center (batch '.$batch->id.'): completed')->assertSuccessful();

        expect($batch->fresh()->status)->toBe('completed');

        $this->artisan('ai:batch:poll')->expectsOutputToContain('No batch is waiting.');
    });

    it('collects every result, writes the ledger at the batch price and reports the saving (Figure 37-7)', function () {
        $batch = submitted(4);

        $this->artisan('ai:batch:poll')->assertSuccessful();

        $this->artisan('ai:batch:collect', ['name' => 'synthesize-help-center'])->expectsOutputToContain('4 results · 0 failed · ledger: 4 rows at batch price · saving vs interactive: 50%')->assertSuccessful();

        $row = AiLedgerEntry::where('kind', 'batch')->first();

        expect(AiLedgerEntry::where('kind', 'batch')->count())->toBe(4)
            ->and($row->feature)->toBe('eval.synthesize')->and($row->provider)->toBe('frontier')->and($row->team)->toBe('platform')
            ->and($row->cost_micro_usd)->toBe((int) round((400 * 3 + 20 * 15) * 0.5))                // 400 in, 20 out at $3 / $15, half price
            ->and($row->entry_id)->toBe("batch:{$batch->id}:chunk:".HelpChunk::orderBy('id')->first()->id)
            ->and($batch->fresh()->status)->toBe('collected');
    });

    it('collects twice and writes nothing the second time', function () {
        submitted(3);

        $this->artisan('ai:batch:poll');

        $this->artisan('ai:batch:collect', ['name' => 'synthesize-help-center'])->assertSuccessful();

        AiBatch::query()->update(['status' => 'completed']);                                                // a crash after the work, before the status: rerun

        $this->artisan('ai:batch:collect', ['name' => 'synthesize-help-center'])->expectsOutputToContain('0 results')->assertSuccessful();

        expect(AiLedgerEntry::where('kind', 'batch')->count())->toBe(3);
    });

    it('resubmits the failed items in a smaller follow-up batch, and the follow-up completes the set', function () {
        $batch = submitted(5);

        $failing = AiBatchItem::where('batch_id', $batch->id)->orderBy('id')->limit(2)->pluck('custom_id');

        $this->fake->failures = $failing->mapWithKeys(fn ($id) => [$id => 'the provider could not process this request'])->all();

        $this->artisan('ai:batch:poll');

        $this->artisan('ai:batch:collect', ['name' => (string) $batch->id])->expectsOutputToContain('5 results · 2 failed (resubmitted as batch '.($batch->id + 1).') · ledger: 3 rows at batch price')->assertSuccessful();

        $follow = AiBatch::where('parent_id', $batch->id)->sole();

        expect($follow->request_count)->toBe(2)->and($follow->status)->toBe('submitted');

        $this->fake->failures = [];

        $this->artisan('ai:batch:poll');

        $this->artisan('ai:batch:collect', ['name' => (string) $follow->id])->expectsOutputToContain('2 results · 0 failed')->assertSuccessful();

        expect(AiLedgerEntry::where('kind', 'batch')->count())->toBe(5);
    });

    it('polls and collects in one step on the schedule', function () {
        submitted(2);

        $this->artisan('ai:batch:poll', ['--collect' => true])->expectsOutputToContain('2 results')->assertSuccessful();

        expect(AiLedgerEntry::where('kind', 'batch')->count())->toBe(2);

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'ai:batch:poll'));

        expect($event->expression)->toBe('*/10 * * * *');
    });

    it('says what it cannot collect', function () {
        $this->artisan('ai:batch:collect', ['name' => 'judge-nightly'])->expectsOutputToContain('waiting to be collected')->assertFailed();
    });

    it('stores the nightly judge\'s scores with the other online judgments', function () {
        [$user] = customerWithInvoice();

        $reply = app(ConversationStore::class)->placeholder(conversationFor($user), 'How do late fees work?');

        $reply->update(['status' => 'completed', 'content' => 'A fee of 1.5% applies.']);

        $this->artisan('ai:batch:submit', ['name' => 'judge-nightly', '--from' => 'replies:1d'])->assertSuccessful();

        $this->artisan('ai:batch:poll', ['--collect' => true])->assertSuccessful();                  // the fake judge answers "4"

        $score = OnlineJudgment::where('metric', 'batch_judge')->sole();

        expect($score->message_id)->toBe($reply->id)->and($score->score)->toBe(0.8);
    });
});

describe('on your own hardware, while it is idle', function () {
    it('works through a share of the waiting requests on each poll, then completes', function () {
        config(['ai.batch.local_per_poll' => 2]);

        chunksToSynthesize(5);

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--provider' => 'local', '--from' => 'help-center'])->assertSuccessful();

        $batch = AiBatch::sole();

        expect($batch->driver)->toBe('local');

        Http::fake(['*/chat/completions' => Http::response(chatBody('How do I waive a late fee?'))]);

        $this->artisan('ai:batch:poll')->expectsOutputToContain('1 submitted, 0 changed state');

        expect(AiBatchItem::where('status', 'ran')->count())->toBe(2);

        $this->artisan('ai:batch:poll');

        $this->artisan('ai:batch:poll')->expectsOutputToContain('completed');

        $this->artisan('ai:batch:collect', ['name' => 'synthesize-help-center'])->expectsOutputToContain('5 results · 0 failed · ledger: 5 rows at batch price · saving vs interactive: n/a (your own hardware has no token price)')->assertSuccessful();

        expect(AiLedgerEntry::where('kind', 'batch')->where('provider', 'local')->count())->toBe(5)
            ->and(AiLedgerEntry::where('kind', 'batch')->sum('cost_micro_usd'))->toBe(0);
    });

    it('marks an item failed when the model does not answer, and the follow-up batch takes it', function () {
        chunksToSynthesize(1);

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--provider' => 'local', '--from' => 'help-center'])->assertSuccessful();

        Http::fake(['*/chat/completions' => Http::response('down', 500)]);

        $this->artisan('ai:batch:poll', ['--collect' => true])->assertSuccessful();

        expect(AiBatchItem::where('status', 'failed')->count())->toBe(1)->and(AiBatch::whereNotNull('parent_id')->count())->toBe(1);
    });
});

describe('a provider\'s batch API', function () {
    it('uploads the file, creates the batch, and reads the output file back', function () {
        config(['ai.providers.frontier.batch_driver' => 'openai']);

        chunksToSynthesize(2);

        Http::fake([
            'frontier.example.com/v1/files' => Http::response(['id' => 'file-in']),
            'frontier.example.com/v1/batches' => Http::response(['id' => 'batch_abc']),
            'frontier.example.com/v1/batches/batch_abc' => Http::sequence()
                ->push(['id' => 'batch_abc', 'status' => 'in_progress'])
                ->push(['id' => 'batch_abc', 'status' => 'completed', 'output_file_id' => 'file-out', 'error_file_id' => 'file-err'])
                ->push(['id' => 'batch_abc', 'status' => 'completed', 'output_file_id' => 'file-out', 'error_file_id' => 'file-err']),
            'frontier.example.com/v1/files/file-out/content' => function () {
                $ids = AiBatchItem::orderBy('id')->pluck('custom_id');

                return Http::response(json_encode(['custom_id' => $ids[0], 'response' => ['status_code' => 200, 'body' => ['model' => 'frontier-example', 'choices' => [['message' => ['content' => 'A question?']]], 'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 20]]]]));
            },
            'frontier.example.com/v1/files/file-err/content' => function () {
                $ids = AiBatchItem::orderBy('id')->pluck('custom_id');

                return Http::response(json_encode(['custom_id' => $ids[1], 'response' => ['status_code' => 400, 'body' => ['error' => ['message' => 'invalid request']]]]));
            },
        ]);

        $this->artisan('ai:batch:submit', ['name' => 'synthesize-help-center', '--from' => 'help-center'])->assertSuccessful();

        expect(AiBatch::sole()->provider_batch_id)->toBe('batch_abc')->and(AiBatch::sole()->driver)->toBe('openai');

        $this->artisan('ai:batch:poll')->expectsOutputToContain('0 changed state');

        $this->artisan('ai:batch:poll')->expectsOutputToContain('completed');

        $this->artisan('ai:batch:collect', ['name' => 'synthesize-help-center'])->expectsOutputToContain('2 results · 1 failed')->assertSuccessful();

        expect(AiBatchItem::where('status', 'done')->count())->toBe(1)->and(AiBatchItem::where('status', 'failed')->first()->error)->toBe('invalid request');
    });
});
