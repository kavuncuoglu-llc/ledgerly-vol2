<?php

use App\Ai\AiManager;
use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\EmbeddingFailed;
use App\Casts\Vector;
use App\Models\HelpChunk;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('prefixes queries and documents the way nomic-embed-text wants', function () {
    fakeEmbeddings();

    $embedder = app(Embedder::class);

    $embedder->query('my invoice is overdue');
    $embedder->document('Late fees are applied after 30 days.');

    Http::assertSent(fn (Request $request) => $request['input'] === 'search_query: my invoice is overdue');
    Http::assertSent(fn (Request $request) => $request['input'] === 'search_document: Late fees are applied after 30 days.');
});

it('posts to the configured embeddings endpoint with the configured model', function () {
    fakeEmbeddings();

    config(['ai.providers.local-embed.base_url' => 'http://models.test/v1', 'ai.embed_model' => 'other-embed']);

    app()->forgetInstance(AiManager::class);
    app()->forgetInstance(Embedder::class);

    app(Embedder::class)->query('hello');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://models.test/v1/embeddings'
        && $request['model'] === 'other-embed'
        && $request->hasHeader('Authorization', 'Bearer ollama'));
});

it('returns the vector from data[0].embedding', function () {
    fakeEmbeddings();

    $vector = app(Embedder::class)->query('late fee');

    expect($vector)->toHaveCount(768)
        ->and(array_sum(array_map(fn (float $v) => $v * $v, $vector)))->toEqualWithDelta(1.0, 0.001);
});

it('refuses a vector whose width does not match the help_chunks column', function () {
    fakeEmbeddings(dimensions: 384);

    app(Embedder::class)->query('late fee');
})->throws(EmbeddingFailed::class, 'returned 384 dimensions');

it('fails clearly when the endpoint returns no vector', function () {
    Http::fake(['*/embeddings' => Http::response(['data' => []])]);

    app(Embedder::class)->query('late fee');
})->throws(EmbeddingFailed::class);

it('writes and reads a pgvector column through the Vector cast', function () {
    $vector = fakeEmbeddingVector('late fee');

    $chunk = HelpChunk::create([
        'source' => 'help/billing/late-fees.md',
        'position' => 1,
        'heading' => 'Late fees',
        'content' => 'Late fees',
        'tokens' => 3,
        'embedding' => $vector,
    ]);

    $stored = $chunk->fresh()->embedding;

    expect($stored)->toHaveCount(768)
        ->and($stored)->toEqualWithDelta($vector, 0.00001)
        ->and(Vector::literal([0.5, -1, 2]))->toBe('[0.5,-1,2]');
});

it('reads a missing vector as null', function () {
    $chunk = HelpChunk::create(['source' => 'x', 'position' => 1, 'heading' => 'x', 'content' => 'x', 'tokens' => 1]);

    expect($chunk->fresh()->embedding)->toBeNull();
});

it('embeds many passages in one request and keeps them in order (Chapter 18)', function () {
    fakeEmbeddings();

    $vectors = app(Embedder::class)->documents(['Late fees start after 30 days.', 'Recurring invoices repeat monthly.', 'Refunds go back to the card.']);

    expect($vectors)->toHaveCount(3)
        ->and($vectors[0])->toEqual(fakeEmbeddingVector('search_document: Late fees start after 30 days.'))
        ->and($vectors[2])->toEqual(fakeEmbeddingVector('search_document: Refunds go back to the card.'));

    Http::assertSentCount(1);

    Http::assertSent(fn (Request $request) => $request['input'] === [
        'search_document: Late fees start after 30 days.',
        'search_document: Recurring invoices repeat monthly.',
        'search_document: Refunds go back to the card.',
    ]);
});

it('puts batched vectors back in order when the server answers out of order', function () {
    Http::fake(['*/embeddings' => Http::response(['data' => [
        ['index' => 1, 'embedding' => array_fill(0, 768, 0.2)],
        ['index' => 0, 'embedding' => array_fill(0, 768, 0.1)],
    ]])]);

    $vectors = app(Embedder::class)->documents(['first', 'second']);

    expect($vectors[0][0])->toBe(0.1)
        ->and($vectors[1][0])->toBe(0.2);
});

it('refuses a batch that comes back short, instead of attaching vectors to the wrong passages', function () {
    Http::fake(['*/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 768, 0.1)]]])]);

    app(Embedder::class)->documents(['first', 'second']);
})->throws(EmbeddingFailed::class, 'returned 1 vectors for 2 passages');

it('makes no request for an empty batch', function () {
    Http::fake();

    expect(app(Embedder::class)->documents([]))->toBe([]);

    Http::assertNothingSent();
});
