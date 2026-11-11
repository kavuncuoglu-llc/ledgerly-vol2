<?php

use App\Ai\Embeddings\ChunkSearch;
use App\Ai\Embeddings\Embedder;
use App\Models\HelpChunk;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

function lateFeeBody(): string
{
    return "## How the fee is calculated\n\nThe late fee is a small percentage of the unpaid balance for every day an invoice stays overdue after the grace period ends.\n\n## When a late fee is applied\n\nA late fee is applied thirty days after the due date, unless the invoice is disputed or on a payment plan.\n";
}

function recurringBody(): string
{
    return "## Repeat every month\n\nA recurring invoice is created on a schedule you choose. Pick monthly and the same invoice is created again every month.\n";
}

beforeEach(function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Understanding late fees', 'billing', lateFeeBody()),
        'invoices/recurring.md' => helpArticleSource('Recurring invoices', 'invoices', recurringBody()),
    ]);
});

it('chunks, embeds and stores every article', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')
        ->expectsOutputToContain('Chunking 2 articles...')
        ->expectsOutputToContain('help:embed is now help:ingest --force')
        ->expectsOutputToContain('with nomic-embed-text')
        ->expectsOutputToContain('Upserted. help_version =')
        ->assertSuccessful();

    $chunk = HelpChunk::query()->where('source', 'help/billing/late-fees.md')->orderBy('position')->first();

    expect(HelpChunk::count())->toBeGreaterThanOrEqual(2)
        ->and($chunk->heading)->toStartWith('Understanding late fees')
        ->and($chunk->embedding)->toHaveCount(768)
        ->and($chunk->tokens)->toBeGreaterThan(0);
});

it('embeds each chunk as a document', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_starts_with($request['input'][0], 'search_document: Understanding late fees › How the fee is calculated'));
});

it('does nothing to the model server on a dry run', function () {
    $this->artisan('help:embed --dry-run')
        ->expectsOutputToContain('Chunking 2 articles...')
        ->assertSuccessful();

    Http::assertNothingSent();

    expect(HelpChunk::count())->toBe(0);
});

it('can be run again without duplicating chunks', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')->assertSuccessful();
    $first = HelpChunk::count();

    $this->artisan('help:embed')->assertSuccessful();

    expect(HelpChunk::count())->toBe($first);
});

it('embeds everything again on every run so a new model gets a full set of vectors', function () {
    Http::fake([
        '*/embeddings' => function (Request $request) {
            $value = $request['model'] === 'nomic-embed-text'
                ? 0.01
                : 0.036;

            return flatEmbeddings($value)($request);
        },
    ]);

    $this->artisan('help:embed')->assertSuccessful();

    expect(HelpChunk::first()->embedding[0])->toEqualWithDelta(0.01, 0.0001);

    config(['ai.embed_model' => 'bge-m3']);
    app()->forgetInstance(Embedder::class);

    $this->artisan('help:embed')->assertSuccessful();

    expect(HelpChunk::first()->embedding[0])->toEqualWithDelta(0.036, 0.0001);
});

it('removes the chunks of an article that no longer exists, and extra positions of one that shrank', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')->assertSuccessful();

    HelpChunk::create(['source' => 'help/billing/late-fees.md', 'position' => 9, 'heading' => 'Gone', 'content' => 'Gone', 'tokens' => 1]);
    HelpChunk::create(['source' => 'help/gone/removed.md', 'position' => 1, 'heading' => 'Gone', 'content' => 'Gone', 'tokens' => 1]);

    $this->artisan('help:embed')->assertSuccessful();

    expect(HelpChunk::where('position', 9)->exists())->toBeFalse()
        ->and(HelpChunk::where('source', 'help/gone/removed.md')->exists())->toBeFalse();
});

it('can embed one article and leave the others alone', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')->assertSuccessful();
    $before = HelpChunk::where('source', 'help/invoices/recurring.md')->count();

    $this->artisan('help:embed --article=billing/late-fees')
        ->expectsOutputToContain('Chunking 1 articles...')
        ->assertSuccessful();

    expect(HelpChunk::where('source', 'help/invoices/recurring.md')->count())->toBe($before);
});

it('fails when there is nothing to embed', function () {
    helpLibrary([]);

    $this->artisan('help:embed')->assertFailed();
});

it('explains a server that cannot be reached', function () {
    Sleep::fake();

    Http::fake(['*/embeddings' => Http::response('no', 500)]);

    $this->artisan('help:embed')->assertFailed();
});

it('explains a model whose vectors are the wrong width', function () {
    fakeEmbeddings(dimensions: 384);

    $this->artisan('help:embed')
        ->expectsOutputToContain('returned 384 dimensions')
        ->assertFailed();
});

it('chunks the real help center within the size targets', function () {
    config(['ledgerly.help_path' => resource_path('help')]);

    $this->artisan('help:embed --dry-run')->assertSuccessful();

    Http::assertNothingSent();
})->skip(fn () => ! is_dir(resource_path('help')) || glob(resource_path('help').'/*') === [], 'resources/help has no articles');

it('finds the late fee section first when asked about a past due bill', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')->assertSuccessful();

    $this->artisan('help:search "late fee unpaid balance overdue after the grace period"')
        ->expectsOutputToContain('help/billing/late-fees.md › Understanding late fees › How the fee is calculated')
        ->assertSuccessful();
});

it('prints similarity first, best match at the top, and honours --limit', function () {
    fakeEmbeddings();

    $this->artisan('help:embed')->assertSuccessful();

    $results = app(ChunkSearch::class)->search('recurring invoice every month', limit: 2);

    expect($results)->toHaveCount(2)
        ->and($results[0]->source)->toBe('help/invoices/recurring.md')
        ->and($results[0]->similarity)->toBeGreaterThan($results[1]->similarity)
        ->and($results[0]->similarity)->toBeLessThanOrEqual(1.0);
});

it('tells you to run help:embed when there are no chunks', function () {
    $this->artisan('help:search "anything"')
        ->expectsOutputToContain('Run php artisan help:embed first')
        ->assertFailed();
});

it('orders by cosine distance using the pgvector operator, exactly', function () {
    $a = array_fill(0, 768, 0.0);
    $b = array_fill(0, 768, 0.0);
    $a[0] = 1.0;
    $b[1] = 1.0;

    HelpChunk::create(['source' => 'help/a.md', 'position' => 1, 'heading' => 'A', 'content' => 'A', 'tokens' => 1, 'embedding' => $a]);
    HelpChunk::create(['source' => 'help/b.md', 'position' => 1, 'heading' => 'B', 'content' => 'B', 'tokens' => 1, 'embedding' => $b]);

    $query = array_fill(0, 768, 0.0);
    $query[0] = 0.8;
    $query[1] = 0.6;

    $results = app(ChunkSearch::class)->nearest($query);

    expect($results->pluck('source')->all())->toBe(['help/a.md', 'help/b.md'])
        ->and((float) $results[0]->similarity)->toEqualWithDelta(0.8, 0.0001)
        ->and((float) $results[1]->similarity)->toEqualWithDelta(0.6, 0.0001);
});

it('has the hnsw cosine index on the embedding column, on halfvec since Chapter 15', function () {
    $index = DB::selectOne("select indexdef from pg_indexes where indexname = 'help_chunks_embedding_hnsw'");

    expect($index->indexdef)->toContain('USING hnsw')->toContain('halfvec_cosine_ops')->toContain('ef_construction');
});

it('has the help_chunks columns the later chapters build on', function () {
    expect(Schema::getColumnListing('help_chunks'))->toBe([
        'id', 'source', 'position', 'heading', 'content', 'tokens', 'created_at', 'updated_at', 'embedding',
        'title', 'heading_path', 'tags', 'help_version', 'source_modified_at', 'chunk_key', 'tenant_id',
        'parent_id', 'kind', 'context_header', 'search',
    ]);
});
