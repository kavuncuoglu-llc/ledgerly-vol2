<?php

use App\Ai\Cache\SemanticCache;
use App\Ai\Schemas\Category;
use App\Models\CachedAnswer;
use App\Models\HelpChunk;
use App\Models\ModelCall;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// Chapter 37, Topic 5: a semantic cache is only safe when its threshold comes from labeled pairs and its answers die with the sources they cited.
function cachedAnswerCiting(array $cited, ?int $tenant = null, string $question = 'How are late fees calculated?'): CachedAnswer
{
    return app(SemanticCache::class)->store($question, Category::HowTo, 'A late fee of 1.5% applies.', $tenant, $cited);
}

describe('invalidation by cited chunk version', function () {
    beforeEach(function () {
        fakeEmbeddings();

        $this->chunk = chunkRow(['content' => "How the fee is calculated\nThe fee is 1.5% of the balance per month."]);

        $this->other = chunkRow(['chunk_key' => 'other', 'heading' => 'Other', 'content' => "Other\nSomething else entirely."]);
    });

    it('forgets the answers that cited a chunk when its text changes', function () {
        $stale = cachedAnswerCiting([['id' => $this->chunk->id, 'version' => $this->chunk->version()]]);

        $unrelated = cachedAnswerCiting([['id' => $this->other->id, 'version' => $this->other->version()]], question: 'How do I do something else?');

        $this->chunk->update(['content' => "How the fee is calculated\nThe fee is 2% of the balance per month."]);

        expect(CachedAnswer::find($stale->id))->toBeNull()
            ->and(CachedAnswer::find($unrelated->id))->not->toBeNull();
    });

    it('keeps an answer when the chunk was only touched, not edited', function () {
        $answer = cachedAnswerCiting([['id' => $this->chunk->id, 'version' => $this->chunk->version()]]);

        $this->chunk->update(['help_version' => 'v9']);                                    // a re-ingest stamps every chunk; the text did not move

        expect(CachedAnswer::find($answer->id))->not->toBeNull();
    });

    it('forgets answers for any tenant that cited the chunk', function () {
        $shared = cachedAnswerCiting([['id' => $this->chunk->id, 'version' => $this->chunk->version()]]);

        config(['ai.semantic_cache.tenant_categories' => ['howto']]);

        $tenant = Category::HowTo;

        $scoped = app(SemanticCache::class)->store('How are late fees calculated for us?', $tenant, 'Ours is 1.5%.', 4, [['id' => $this->chunk->id, 'version' => $this->chunk->version()]]);

        $this->chunk->update(['content' => 'Changed.']);

        expect(CachedAnswer::whereIn('id', [$shared->id, $scoped->id])->count())->toBe(0);
    });

    it('forgets every answer that cited a chunk that was deleted', function () {
        $answer = cachedAnswerCiting([['id' => $this->chunk->id, 'version' => $this->chunk->version()]]);

        $this->chunk->delete();

        expect(CachedAnswer::find($answer->id))->toBeNull();
    });

    it('leaves an answer with no recorded sources to the help_version and the schedule, as before', function () {
        $answer = cachedAnswerCiting([]);

        $this->chunk->update(['content' => 'Changed.']);

        expect(CachedAnswer::find($answer->id))->not->toBeNull();
    });

    it('records the cited chunks and their versions when the assistant stores an answer', function () {
        $answer = cachedAnswerCiting([['id' => 3, 'version' => 'abc123abc123']]);

        expect($answer->fresh()->cited_chunks)->toBe([['id' => 3, 'version' => 'abc123abc123']]);
    });

    it('gives a chunk a version that moves with its text', function () {
        $before = $this->chunk->version();

        $this->chunk->content = 'Different text.';

        expect($this->chunk->version())->not->toBe($before)->and(strlen($before))->toBe(12);
    });

    it('is called from help:ingest when a re-ingest changes an article', function () {
        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## How the fee is calculated\n\nThe fee is 1.5% of the outstanding balance per month.\n")]);

        $this->artisan('help:ingest')->assertSuccessful();

        $chunk = HelpChunk::where('source', 'help/billing/late-fees.md')->first();

        $answer = cachedAnswerCiting([['id' => $chunk->id, 'version' => $chunk->version()]]);

        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## How the fee is calculated\n\nThe fee is 2% of the outstanding balance per month.\n")]);

        $this->artisan('help:ingest --article=billing/late-fees')->assertSuccessful();

        expect(CachedAnswer::find($answer->id))->toBeNull();
    });
});

describe('ai:cache-tune', function () {
    function pairsFile(array $pairs): string
    {
        $path = sys_get_temp_dir().'/pairs-'.uniqid().'.jsonl';

        File::put($path, collect($pairs)->map(fn (array $pair) => json_encode($pair))->implode("\n"));

        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }

    function pairWorld(bool $separable = false): array
    {
        $angles = $separable
            ? ['export invoices' => 0.0, 'download all invoices' => 4.0, 'delete an invoice' => 20.0, 'delete a customer' => 45.0, 'reset password' => 90.0, 'forgot password' => 94.0, 'refund a payment' => 140.0, 'refund a cheque' => 170.0]
            : ['export invoices' => 0.0, 'download all invoices' => 8.0, 'delete an invoice' => 20.0, 'delete a customer' => 25.0, 'reset password' => 90.0, 'forgot password' => 96.0, 'refund a payment' => 140.0, 'refund a cheque' => 143.0];

        Http::fake(['*/embeddings' => fakeEmbeddingAngles($angles)]);

        return [
            ['a' => 'export invoices', 'b' => 'download all invoices', 'same' => true],
            ['a' => 'reset password', 'b' => 'forgot password', 'same' => true],
            ['a' => 'delete an invoice', 'b' => 'delete a customer', 'same' => false],
            ['a' => 'refund a payment', 'b' => 'refund a cheque', 'same' => false],
        ];
    }

    it('prints the hit rate, false-hit rate and precision at each threshold and recommends the lowest that keeps false hits down', function () {
        $file = pairsFile(pairWorld(separable: true));

        [$code, $output] = artisanOutput('ai:cache-tune', ['--pairs' => $file]);

        expect($code)->toBe(0)
            ->and($output)->toContain('hit rate (same-answer pairs)')
            ->and($output)->toMatch('/recommended: AI_SEMANTIC_CACHE_THRESHOLD=0\.\d\d/')
            ->and($output)->toContain('2 same-answer pairs, 2 different-answer pairs');
    });

    it('picks the threshold where the look-alike pairs stop hitting', function () {
        $file = pairsFile(pairWorld());

        [, $output] = artisanOutput('ai:cache-tune', ['--pairs' => $file, '--from' => '0.90', '--to' => '1.00', '--max-false-hit' => '0']);

        // the rephrasings are 8 and 6 degrees apart (cosine 0.990 and 0.995); the look-alikes 5 and 3 degrees apart (0.996 and 0.9986): no threshold separates them
        expect($output)->toContain('No threshold up to 1.00 keeps false hits under 0% and still hits');
    });

    it('separates them when the data does', function () {
        $angles = ['a' => 0.0, 'a2' => 5.0, 'b' => 40.0, 'b2' => 80.0];

        Http::fake(['*/embeddings' => fakeEmbeddingAngles($angles)]);

        $file = pairsFile([['a' => 'a', 'b' => 'a2', 'same' => true], ['a' => 'b', 'b' => 'b2', 'same' => false]]);

        [$code, $output] = artisanOutput('ai:cache-tune', ['--pairs' => $file, '--max-false-hit' => '0']);

        expect($code)->toBe(0)->and($output)->toContain('hit rate 100%, false hits 0%, precision 100%');
    });

    it('fails clearly on a missing file, a file of one kind, and a model that does not answer', function () {
        $this->artisan('ai:cache-tune', ['--pairs' => '/no/such/file.jsonl'])->assertFailed();

        $this->artisan('ai:cache-tune', ['--pairs' => pairsFile([['a' => 'x', 'b' => 'y', 'same' => true]])])->expectsOutputToContain('needs pairs of both kinds')->assertFailed();

        Http::fake(['*/embeddings' => Http::response('down', 500)]);

        $this->artisan('ai:cache-tune', ['--pairs' => pairsFile([['a' => 'x', 'b' => 'y', 'same' => true], ['a' => 'p', 'b' => 'q', 'same' => false]])])->expectsOutputToContain('did not answer')->assertFailed();
    });

    it('ships labeled pairs of both kinds, written for Ledgerly', function () {
        $pairs = collect(file(base_path('tests/Fixtures/cache/pairs.jsonl'), FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));

        expect($pairs->where('same', true)->count())->toBeGreaterThanOrEqual(20)
            ->and($pairs->where('same', false)->count())->toBeGreaterThanOrEqual(20)
            ->and($pairs->pluck('a')->merge($pairs->pluck('b'))->implode(' '))->not->toContain('@');
    });

    it('reads its default threshold from the environment', function () {
        expect(config('ai.semantic_cache.threshold'))->toBe(0.92)->and(config('ai.semantic_cache.max_false_hit_rate'))->toBe(0.01);
    });
});

describe('ai:cache-share', function () {
    function cachedCall(int $input, int $cached, string $feature = 'support.reply'): void
    {
        ModelCall::create(['feature' => $feature, 'provider' => 'frontier', 'model' => 'm', 'prompt_tokens' => $input, 'completion_tokens' => 10, 'finish_reason' => 'stop', 'latency_ms' => 1, 'cached_input_tokens' => $cached]);
    }

    it('passes a feature over its floor', function () {
        cachedCall(3_500, 2_800);

        $this->artisan('ai:cache-share')->expectsOutputToContain('80%')->assertSuccessful();
    });

    it('fails a feature under its floor, and tells its team with --notify', function () {
        config(['ai.teams.support.email' => ['support@ledgerly.test']]);

        cachedCall(3_500, 100);

        $this->artisan('ai:cache-share', ['--notify' => true])->assertFailed();

        $recipients = collect(Mail::mailer()->getSymfonyTransport()->messages())->flatMap(fn ($m) => collect($m->getEnvelope()->getRecipients())->map->getAddress())->all();

        expect($recipients)->toContain('support@ledgerly.test');
    });

    it('ignores calls too short to cache, and is scheduled hourly', function () {
        cachedCall(300, 0);

        $this->artisan('ai:cache-share')->expectsOutputToContain('No calls long enough to cache')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'ai:cache-share'));

        expect($event->expression)->toBe('0 * * * *');
    });
});
