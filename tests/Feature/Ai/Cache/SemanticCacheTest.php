<?php

use App\Ai\Cache\HelpVersion;
use App\Ai\Cache\SemanticCache;
use App\Ai\Schemas\Category;
use App\Casts\Vector;
use App\Models\CachedAnswer;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.semantic_cache.enabled' => true, 'ai.semantic_cache.require_context' => false]);

    Http::fake(['*/embeddings' => fakeEmbeddingAngles([
        'How do I set up recurring invoices?' => 0,
        'Can I make an invoice repeat every month?' => 15,       // cos 15 degrees = 0.966: the same question
        'How do I send an invoice to a client?' => 40,           // cos 40 degrees = 0.766: a different one
        'How do I add a new client?' => 180,
    ])]);

    $this->cache = app(SemanticCache::class);
});

function storedAnswer(array $overrides = []): CachedAnswer
{
    return CachedAnswer::create($overrides + [
        'tenant_id' => null,
        'category' => 'howto',
        'question' => 'How do I set up recurring invoices?',
        'answer' => 'Open the invoice, choose Make recurring, and set the interval.',
        'help_version' => 'v1',
        'status' => 'auto',
        'embedding' => angledVector(0),
    ]);
}

describe('lookup', function () {
    it('returns the nearest cached answer above the threshold, with its similarity', function () {
        storedAnswer();

        $hit = $this->cache->lookup('Can I make an invoice repeat every month?', Category::HowTo, tenantId: 12);

        expect($hit->question)->toBe('How do I set up recurring invoices?')
            ->and($hit->answer)->toContain('Make recurring')
            ->and(round($hit->similarity, 3))->toBe(0.966);
    });

    it('misses below the threshold', function () {
        storedAnswer();

        expect($this->cache->lookup('How do I send an invoice to a client?', Category::HowTo, 12))->toBeNull();
    });

    it('hits on the exact question with similarity 1', function () {
        storedAnswer();

        expect(round($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12)->similarity, 3))->toBe(1.0);
    });

    it('uses a per-category threshold when there is one', function () {
        storedAnswer();

        config(['ai.semantic_cache.thresholds' => ['howto' => 0.70]]);

        expect($this->cache->lookup('How do I send an invoice to a client?', Category::HowTo, 12))->not->toBeNull();

        config(['ai.semantic_cache.thresholds' => ['howto' => 0.99]]);

        expect($this->cache->lookup('Can I make an invoice repeat every month?', Category::HowTo, 12))->toBeNull();
    });

    it('answers nothing for a category that is not cacheable', function () {
        storedAnswer(['category' => 'billing']);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::Billing, 12))->toBeNull();
    });

    it('only looks in the category asked for', function () {
        storedAnswer(['category' => 'billing']);

        config(['ai.semantic_cache.categories' => ['howto', 'billing']]);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12))->toBeNull()
            ->and($this->cache->lookup('How do I set up recurring invoices?', Category::Billing, 12))->not->toBeNull();
    });

    it('does not serve an answer written against another version of the help center', function () {
        storedAnswer(['help_version' => 'v0']);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12))->toBeNull();

        config(['ledgerly.help_version' => 'v0']);

        app(HelpVersion::class)->forget();

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12))->not->toBeNull();
    });

    it('serves auto and approved answers, never rejected ones', function (string $status, bool $served) {
        storedAnswer(['status' => $status]);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12) !== null)->toBe($served);
    })->with([['auto', true], ['approved', true], ['rejected', false]]);

    it('does not serve an expired answer, and serves one with no expiry or a future one', function () {
        storedAnswer(['expires_at' => now()->subMinute()]);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12))->toBeNull();

        CachedAnswer::query()->delete();

        storedAnswer(['expires_at' => now()->addDay()]);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12))->not->toBeNull();
    });

    it('shares an answer with tenant null across tenants but keeps a tenant\'s own to that tenant', function () {
        storedAnswer(['tenant_id' => 7, 'answer' => 'tenant seven only']);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 7)->answer)->toBe('tenant seven only')
            ->and($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 8))->toBeNull();

        storedAnswer(['tenant_id' => null, 'answer' => 'for everyone']);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 8)->answer)->toBe('for everyone');
    });

    it('returns the nearest of several', function () {
        storedAnswer(['question' => 'How do I add a new client?', 'embedding' => angledVector(180), 'answer' => 'far']);
        storedAnswer(['question' => 'How do I set up recurring invoices?', 'embedding' => angledVector(5), 'answer' => 'near']);
        storedAnswer(['question' => 'Another', 'embedding' => angledVector(8), 'answer' => 'nearish']);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12)->answer)->toBe('near');
    });

    it('does not pull the embedding column back with the row', function () {
        storedAnswer();

        $hit = $this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12);

        expect(array_key_exists('embedding', $hit->getAttributes()))->toBeFalse();
    });

    it('misses, and does not fail, when the question cannot be embedded', function () {
        storedAnswer();

        Http::swap(new Factory);                                      // fakes do not replace earlier ones

        Http::fake(['*/embeddings' => Http::response('down', 503)]);

        expect($this->cache->lookup('How do I set up recurring invoices?', Category::HowTo, 12))->toBeNull();
    });

    it('embeds a question once for a lookup followed by a store', function () {
        $this->cache->find('How do I set up recurring invoices?', 12);
        $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'answer', 12);

        Http::assertSentCount(1);
    });
});

describe('find', function () {
    it('looks across every cacheable category and returns the row\'s own category', function () {
        config(['ai.semantic_cache.categories' => ['howto', 'billing']]);

        storedAnswer(['category' => 'billing']);

        expect($this->cache->find('How do I set up recurring invoices?', 12)->category)->toBe('billing');
    });

    it('finds nothing when no category is cacheable', function () {
        storedAnswer();

        config(['ai.semantic_cache.categories' => []]);

        expect($this->cache->find('How do I set up recurring invoices?', 12))->toBeNull();
    });

    it('applies each category\'s own threshold', function () {
        config(['ai.semantic_cache.categories' => ['howto', 'billing'], 'ai.semantic_cache.thresholds' => ['billing' => 0.99]]);

        storedAnswer(['category' => 'billing']);

        expect($this->cache->find('Can I make an invoice repeat every month?', 12))->toBeNull();

        storedAnswer(['category' => 'howto']);

        expect($this->cache->find('Can I make an invoice repeat every month?', 12)->category)->toBe('howto');
    });
});

describe('store', function () {
    it('stores the question, its embedding, the answer and the help version, shared by default', function () {
        $stored = $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'Open the invoice.', 12);

        $row = CachedAnswer::sole();

        expect($row->id)->toBe($stored->id)
            ->and($row->tenant_id)->toBeNull()
            ->and($row->category)->toBe('howto')
            ->and($row->question)->toBe('How do I set up recurring invoices?')
            ->and($row->answer)->toBe('Open the invoice.')
            ->and($row->help_version)->toBe('v1')
            ->and($row->status)->toBe('auto')
            ->and($row->hit_count)->toBe(0)
            ->and($row->expires_at->isFuture())->toBeTrue()
            ->and($row->embedding)->toBe(angledVector(0));
    });

    it('stores a tenant-specific category under the tenant', function () {
        config(['ai.semantic_cache.categories' => ['howto', 'account'], 'ai.semantic_cache.tenant_categories' => ['account']]);

        $this->cache->store('How do I set up recurring invoices?', Category::Account, 'Our setup.', 12);

        expect(CachedAnswer::sole()->tenant_id)->toBe(12);
    });

    it('never stores a category that is not cacheable', function () {
        expect($this->cache->store('Why is my March invoice late?', Category::Billing, 'Because.', 12))->toBeNull()
            ->and(CachedAnswer::count())->toBe(0);
    });

    it('updates the same question instead of adding a duplicate', function () {
        $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'First.', 12);
        $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'Second.', 12);

        expect(CachedAnswer::count())->toBe(1)
            ->and(CachedAnswer::first()->answer)->toBe('Second.');
    });

    it('keeps a different row per help version', function () {
        $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'First.', 12);

        config(['ledgerly.help_version' => 'v2']);

        app(HelpVersion::class)->forget();

        $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'Second.', 12);

        expect(CachedAnswer::count())->toBe(2);
    });

    it('does not replace an answer a person approved', function () {
        storedAnswer(['status' => 'approved', 'answer' => 'Reviewed by Priya.']);

        $this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'A fresh model answer.', 12);

        expect(CachedAnswer::sole()->answer)->toBe('Reviewed by Priya.');
    });

    it('stores nothing, and does not fail, when the question cannot be embedded', function () {
        Http::swap(new Factory);                                      // fakes do not replace earlier ones

        Http::fake(['*/embeddings' => Http::response('down', 503)]);

        expect($this->cache->store('How do I set up recurring invoices?', Category::HowTo, 'Open.', 12))->toBeNull()
            ->and(CachedAnswer::count())->toBe(0);
    });

    it('counts hits', function () {
        $row = storedAnswer();

        $this->cache->recordHit($row);
        $this->cache->recordHit($row);

        expect($row->fresh()->hit_count)->toBe(2);
    });
});

describe('the table', function () {
    it('has the Figure 14-4 columns, a vector(768) embedding and an HNSW cosine index', function () {
        expect(Schema::getColumnListing('cached_answers'))->toBe(['id', 'tenant_id', 'category', 'question', 'answer', 'help_version', 'status', 'hit_count', 'expires_at', 'created_at', 'updated_at', 'embedding']);

        $index = DB::selectOne("select indexdef from pg_indexes where indexname = 'cached_answers_embedding_idx'")->indexdef;

        expect($index)->toContain('USING hnsw')->toContain('vector_cosine_ops');

        $type = DB::selectOne("select format_type(atttypid, atttypmod) as t from pg_attribute where attrelid = 'cached_answers'::regclass and attname = 'embedding'")->t;

        expect($type)->toBe('vector(768)');
    });

    it('does the similarity maths the SQL promises', function () {
        storedAnswer();

        $similarity = DB::selectOne('select 1 - (embedding <=> ?::vector) as s from cached_answers', [Vector::literal(angledVector(60))])->s;

        expect(round($similarity, 3))->toBe(0.5);                         // cos 60 degrees
    });
});
