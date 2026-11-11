<?php

use App\Ai\Cache\HelpVersion;
use App\Ai\Ingestion\HelpIngestor;
use App\Help\HelpArticle;
use App\Models\HelpChunk;
use App\Models\Tenant;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function lateFeesArticle(string $when = 'A late fee is applied automatically 30 days after the due date.'): string
{
    return helpArticleSource('Late fees', 'billing', "## How the fee is calculated\n\nThe fee is 1.5% of the outstanding balance per month, prorated by day.\n\n## When a late fee is applied\n\n{$when}\n");
}

function recurringArticle(): string
{
    return helpArticleSource('Recurring invoices', 'invoices', "## Repeat every month\n\nPick monthly and the same invoice is created again every month.\n\nWas this article helpful? Tell us.\n");
}

function documentRequests(): int
{
    return Http::recorded()->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/embeddings'))->count();
}

beforeEach(function () {
    helpLibrary([
        'billing/late-fees.md' => lateFeesArticle(),
        'invoices/recurring.md' => recurringArticle(),
    ]);

    fakeEmbeddings();
});

it('chunks, embeds and stores the library with metadata, a version and a keyword index', function () {
    $this->artisan('help:ingest --help-version=7b2e9d0')
        ->expectsOutputToContain('Stripping boilerplate (4 patterns)...')
        ->expectsOutputToContain('Chunking 2 articles...')
        ->expectsOutputToContain('Diff vs nothing: 0 changed, 2 new, 0 removed, 0 unchanged')
        ->expectsOutputToContain('Upserted. help_version = 7b2e9d0')
        ->assertSuccessful();

    $chunk = HelpChunk::query()->where('source', 'help/billing/late-fees.md')->orderBy('position')->first();

    expect(HelpChunk::count())->toBe(2)
        ->and($chunk->title)->toBe('Late fees')
        ->and($chunk->heading_path)->toBe('Billing › Late fees › How the fee is calculated')
        ->and($chunk->tags)->toBe(['area' => 'billing'])
        ->and($chunk->help_version)->toBe('7b2e9d0')
        ->and($chunk->chunk_key)->toHaveLength(40)
        ->and($chunk->tenant_id)->toBeNull()
        ->and($chunk->source_modified_at->toDateString())->toBe('2025-10-02')
        ->and($chunk->embedding)->toHaveCount(768);
});

it('makes the generated tsvector column searchable', function () {
    $this->artisan('help:ingest')->assertSuccessful();

    $found = HelpChunk::query()->whereRaw("search @@ websearch_to_tsquery('english', ?)", ['prorated'])->pluck('source')->all();

    expect($found)->toBe(['help/billing/late-fees.md']);
});

it('strips boilerplate before chunking, so it never becomes part of a chunk', function () {
    $this->artisan('help:ingest')->assertSuccessful();

    expect(HelpChunk::query()->where('content', 'like', '%Was this article helpful%')->exists())->toBeFalse()
        ->and(HelpChunk::query()->where('source', 'help/invoices/recurring.md')->value('content'))->toContain('Pick monthly');
});

it('stamps the default version from the articles when none is given, and HelpVersion then reads it back from the chunks', function () {
    config(['ledgerly.help_version' => null]);

    $this->artisan('help:ingest')->assertSuccessful();

    $stamped = HelpChunk::query()->value('help_version');

    expect($stamped)->toBe(app(HelpVersion::class)->ofArticles())
        ->and(app(HelpVersion::class)->current())->toBe($stamped);
});

it('is idempotent: a second run embeds nothing and changes nothing', function () {
    $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

    $ids = HelpChunk::orderBy('id')->pluck('id')->all();

    Http::swap(new Factory);

    Http::fake();

    $this->artisan('help:ingest --help-version=v1')
        ->expectsOutputToContain('Diff vs v1: 0 changed, 0 new, 0 removed, 2 unchanged')
        ->expectsOutputToContain('Embedded 0 chunks')
        ->assertSuccessful();

    Http::assertNothingSent();

    expect(HelpChunk::orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('embeds only the chunk whose text changed and leaves the other embeddings alone', function () {
    $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

    $untouched = HelpChunk::query()->where('source', 'help/invoices/recurring.md')->first();

    helpLibrary([
        'billing/late-fees.md' => lateFeesArticle('A late fee is applied automatically 45 days after the due date.'),
        'invoices/recurring.md' => recurringArticle(),
    ]);

    Http::swap(new Factory);

    Http::fake(['*/embeddings' => flatEmbeddings(0.5)]);

    $this->artisan('help:ingest --help-version=v2')
        ->expectsOutputToContain('Diff vs v1: 1 changed, 0 new, 0 removed, 1 unchanged')
        ->assertSuccessful();

    expect(documentRequests())->toBe(1)
        ->and(HelpChunk::query()->where('help_version', 'v2')->count())->toBe(2)
        ->and(HelpChunk::query()->where('content', 'like', '%45 days%')->first()->embedding[0])->toBe(0.5)
        ->and(HelpChunk::find($untouched->id)->embedding)->toBe($untouched->embedding);
});

it('adds new chunks and removes the chunks of an article that went away', function () {
    $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

    helpLibrary([
        'billing/late-fees.md' => lateFeesArticle(),
        'payments/refunds.md' => helpArticleSource('Refunds', 'payments', "## Issuing a refund\n\nOpen the payment and choose Refund.\n"),
    ]);

    $this->artisan('help:ingest --help-version=v2')
        ->expectsOutputToContain('Diff vs v1: 0 changed, 1 new, 1 removed, 1 unchanged')
        ->assertSuccessful();

    expect(HelpChunk::query()->where('source', 'help/invoices/recurring.md')->exists())->toBeFalse()
        ->and(HelpChunk::query()->where('source', 'help/payments/refunds.md')->exists())->toBeTrue();
});

it('re-embeds everything with --force, for example after swapping the embedding model', function () {
    $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

    Http::swap(new Factory);

    Http::fake(['*/embeddings' => flatEmbeddings(0.25)]);

    $this->artisan('help:ingest --help-version=v1 --force')->expectsOutputToContain('Embedded 2 chunks')->assertSuccessful();

    expect(documentRequests())->toBe(1)                                      // Chapter 18: both chunks travel in one request
        ->and(HelpChunk::first()->embedding[0])->toBe(0.25);
});

it('ingests one article and keeps the library on one help_version', function () {
    $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

    $this->artisan('help:ingest --article=billing/late-fees --help-version=somethingelse')->assertSuccessful();

    expect(HelpChunk::query()->pluck('help_version')->unique()->all())->toBe(['v1']);
});

it('does nothing on a dry run but still reports the diff', function () {
    $this->artisan('help:ingest --dry-run')
        ->expectsOutputToContain('Diff vs nothing: 0 changed, 2 new, 0 removed, 0 unchanged')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Http::assertNothingSent();

    expect(HelpChunk::count())->toBe(0);
});

it('uses tenant-less rows only: a tenant-private chunk is never touched by ingestion', function () {
    $tenant = Tenant::factory()->create();

    $private = chunkRow(['tenant_id' => $tenant->id, 'source' => 'help/private/acme.md', 'help_version' => 'private']);

    $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

    expect(HelpChunk::find($private->id))->not->toBeNull()
        ->and(HelpChunk::find($private->id)->help_version)->toBe('private');
});

it('fails clearly when there is nothing to ingest', function () {
    helpLibrary([]);

    $this->artisan('help:ingest')->assertFailed();
});

it('explains a model that cannot be reached and leaves the table as it was', function () {
    Sleep::fake();

    Http::swap(new Factory);

    Http::fake(['*/embeddings' => Http::response('no', 500)]);

    $this->artisan('help:ingest')->assertFailed();

    expect(HelpChunk::count())->toBe(0);
});

it('chunks the real library into unique keys', function () {
    config(['ledgerly.help_path' => resource_path('help')]);

    $prepared = app(HelpIngestor::class)->prepare(HelpArticle::all());

    expect($prepared->count())->toBeGreaterThan(300)
        ->and($prepared->pluck('key')->unique()->count())->toBe($prepared->count())
        ->and($prepared->first()->tags)->toHaveKey('area');
})->skip(fn () => ! is_dir(resource_path('help')) || glob(resource_path('help').'/*') === [], 'resources/help has no articles');

it('embeds in batches of 48 passages: 100 chunks cost three requests, not a hundred (Chapter 18)', function () {
    $files = [];

    foreach (range(1, 100) as $i) {
        $files["billing/article-{$i}.md"] = helpArticleSource("Article {$i}", 'billing', "## Only section\n\nThis is the text of article number {$i}, long enough to stand as one chunk of its own.\n");
    }

    helpLibrary($files);

    Http::swap(new Factory);

    fakeEmbeddings();

    $this->artisan('help:ingest --help-version=batch')->assertSuccessful();

    expect(HelpChunk::count())->toBe(100)
        ->and(documentRequests())->toBe(3);
});
