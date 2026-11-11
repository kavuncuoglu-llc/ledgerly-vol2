<?php

use App\Ai\Embeddings\MarkdownChunker;
use App\Ai\Ingestion\ChunkSizeGate;
use App\Ai\Tokens\RatioCounter;
use App\Models\HelpChunk;

// Chapter 35, Topic 4: Chapter 28's dataset_stats.py as a gate. A headingless export used to ingest without a word.
function articleOfWords(string $title, int $words): string
{
    return helpArticleSource($title, 'billing', str_repeat('The fee is charged each month on the outstanding balance of the invoice. ', (int) ($words / 13)));
}

// Volume 1's chunker also splits a section that runs over 400 tokens, so a headingless export alone cannot make a 4,000-token chunk here (BOOK-ISSUES). A chunker with the limit lifted is the export that did it in the postmortem.
function chunkerThatDoesNotSplit(): void
{
    app()->bind(MarkdownChunker::class, fn () => new MarkdownChunker(new RatioCounter, 200, 20_000));
}

beforeEach(function () {
    fakeEmbeddings();
});

it('ingests a library of sensible chunks', function () {
    helpLibrary([
        'billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "## How the fee works\n\nThe fee is 1.5% a month.\n\n## When it applies\n\nAfter 30 days.\n"),
    ]);

    $this->artisan('help:ingest')->assertSuccessful();

    expect(HelpChunk::count())->toBeGreaterThan(0);
});

it('refuses a library whose articles became single chunks of thousands of tokens, and writes nothing', function () {
    chunkerThatDoesNotSplit();

    helpLibrary(collect(range(1, 6))->mapWithKeys(fn (int $i) => ["billing/article-{$i}.md" => articleOfWords("Article {$i}", 4_000)])->all());

    $this->artisan('help:ingest')
        ->expectsOutputToContain('Chunk sizes moved: 100% of chunks are longer than 512 tokens')
        ->assertFailed();

    expect(HelpChunk::count())->toBe(0);
});

it('writes them anyway when the new sizes are intended (--accept-chunk-sizes)', function () {
    chunkerThatDoesNotSplit();

    helpLibrary(['billing/article-1.md' => articleOfWords('Article 1', 4_000)]);

    $this->artisan('help:ingest --accept-chunk-sizes')->assertSuccessful();

    expect(HelpChunk::count())->toBeGreaterThan(0);
});

it('can be switched off', function () {
    chunkerThatDoesNotSplit();

    config()->set('ai.ingest.chunk_gate.enabled', false);

    helpLibrary(['billing/article-1.md' => articleOfWords('Article 1', 4_000)]);

    $this->artisan('help:ingest')->assertSuccessful();
});

it('fails the ingest of a mean far from what is already indexed', function () {
    foreach (range(1, 12) as $i) {
        HelpChunk::create(['source' => "help/billing/old-{$i}.md", 'position' => 1, 'heading' => 'Old', 'content' => 'old', 'tokens' => 200, 'kind' => 'parent']);
    }

    $reasons = (new ChunkSizeGate)->reasons([400, 420, 410, 430], 200.0);

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('the mean chunk went from 200 to 415 tokens');
});

it('does not compare against a handful of chunks', function () {
    HelpChunk::create(['source' => 'help/billing/old.md', 'position' => 1, 'heading' => 'Old', 'content' => 'old', 'tokens' => 1, 'kind' => 'parent']);

    expect((new ChunkSizeGate)->reasons([300, 310], null))->toBe([]);
});

it('allows the few chunks over the reranker window that a real library has', function () {
    $sizes = array_merge(array_fill(0, 97, 250), [600, 700, 650]);

    expect((new ChunkSizeGate)->reasons($sizes, 250.0))->toBe([]);
});
