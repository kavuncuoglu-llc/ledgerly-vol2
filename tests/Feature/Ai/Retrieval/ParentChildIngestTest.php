<?php

use App\Ai\Embeddings\Chunk;
use App\Ai\Ingestion\ChildSplitter;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Tokens\RatioCounter;
use App\Models\HelpChunk;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function longFeeArticle(): string
{
    $rate = 'The fee is 1.5 percent of the outstanding balance per month, prorated by day. It is calculated on the invoice total excluding any previous late fees. '.str_repeat('The rate applies to every plan in the same way. ', 6);
    $minimum = 'For partial months the daily rate is the monthly rate divided by thirty. A fee that would be less than one dollar is not charged at all. '.str_repeat('Rounding always goes down to the cent. ', 6);
    $waive = 'You can waive a fee from the invoice page. Choose the fee and select waive. '.str_repeat('The waiver is recorded in the invoice history. ', 6);

    return helpArticleSource('Late fees', 'billing', "## How the fee is calculated\n\n{$rate}\n\n{$minimum}\n\n{$waive}\n");
}

beforeEach(function () {
    helpLibrary(['billing/late-fees.md' => longFeeArticle()]);

    fakeEmbeddings();

    config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);
});

describe('ChildSplitter', function () {
    it('groups paragraphs into children of about the child size, without the breadcrumb line', function () {
        $parent = new Chunk('help/x.md', 1, 'Title › Section', "Title › Section\n".str_repeat("A paragraph of exactly this size goes here for the test.\n\n", 12), 400);

        $children = (new ChildSplitter(new RatioCounter, 60))->split($parent);

        expect(count($children))->toBeGreaterThan(2)
            ->and(collect($children)->every(fn (string $c) => (new RatioCounter)->count($c) <= 60))->toBeTrue()
            ->and($children[0])->not->toContain('Title › Section');
    });

    it('splits a paragraph that is longer than a child on its sentences', function () {
        $parent = new Chunk('help/x.md', 1, 'T › S', "T › S\n".str_repeat('One sentence of ordinary length sits here. ', 20), 400);

        $children = (new ChildSplitter(new RatioCounter, 60))->split($parent);

        expect(count($children))->toBeGreaterThan(2)
            ->and(collect($children)->every(fn (string $c) => str_ends_with($c, '.')))->toBeTrue();
    });

    it('returns one piece for a short section, so the parent matches on itself', function () {
        $parent = new Chunk('help/x.md', 1, 'T › S', "T › S\nA short section.", 10);

        expect((new ChildSplitter(new RatioCounter, 160))->split($parent))->toBe(['A short section.']);
    });
});

describe('help:ingest --strategy=parent-child', function () {
    it('stores each section as a parent without a vector and embeds its children, with the title and heading as a header', function () {
        $this->artisan('help:ingest --strategy=parent-child --context-headers')
            ->expectsOutputToContain('Parent-child: 1 sections')
            ->assertSuccessful();

        $parent = HelpChunk::where('kind', 'parent')->sole();
        $children = HelpChunk::where('kind', 'child')->orderBy('id')->get();

        expect($parent->embedding)->toBeNull()
            ->and($children->count())->toBeGreaterThan(1)
            ->and($children->pluck('parent_id')->unique()->all())->toBe([$parent->id])
            ->and($children[0]->embedding)->toHaveCount(768)
            ->and($children[0]->context_header)->toBe('Late fees › How the fee is calculated')
            ->and($children[0]->content)->not->toStartWith('Late fees')
            ->and($children[0]->heading_path)->toBe($parent->heading_path)
            ->and($children->pluck('chunk_key')->unique()->count())->toBe($children->count());
    });

    it('embeds the header and the child text together', function () {
        $this->artisan('help:ingest --strategy=parent-child --context-headers')->assertSuccessful();

        Http::assertSent(fn (Request $request) => collect((array) $request['input'])->contains(fn (string $text) => str_starts_with($text, "search_document: Late fees › How the fee is calculated\n\n")
            && str_contains($text, 'daily rate')));
    });

    it('leaves the header off with the none mode', function () {
        config(['ai.retrieval.context_headers' => 'none']);

        $this->artisan('help:ingest --strategy=parent-child')->assertSuccessful();

        expect(HelpChunk::where('kind', 'child')->pluck('context_header')->filter()->all())->toBe([]);
    });

    it('matches on the child but returns the parent section, once', function () {
        $this->artisan('help:ingest --strategy=parent-child --context-headers')->assertSuccessful();

        $result = app(Retriever::class)->retrieve('is there a minimum late fee, less than one dollar is not charged', RetrievalScope::shared(), 5);

        $parent = HelpChunk::where('kind', 'parent')->sole();

        expect($result)->toHaveCount(1)
            ->and($result[0]->id)->toBe($parent->id)
            ->and($result[0]->body())->toContain('1.5 percent')->toContain('waive a fee')
            ->and($result[0]->matched)->toContain('Late fees › How the fee is calculated')->toContain('less than one dollar')
            ->and($result[0]->matchText())->toBe($result[0]->matched);
    });

    it('is idempotent and embeds nothing the second time', function () {
        $this->artisan('help:ingest --strategy=parent-child --context-headers --help-version=v1')->assertSuccessful();

        Http::swap(new Factory);

        Http::fake();

        $this->artisan('help:ingest --strategy=parent-child --context-headers --help-version=v1')
            ->expectsOutputToContain('0 changed, 0 new, 0 removed')
            ->expectsOutputToContain('Embedded 0 chunks')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('switches strategy both ways: the parent loses its vector, then gets it back', function () {
        $this->artisan('help:ingest --help-version=v1')->assertSuccessful();

        expect(HelpChunk::sole()->embedding)->toHaveCount(768);

        $this->artisan('help:ingest --strategy=parent-child --help-version=v1')->assertSuccessful();

        expect(HelpChunk::where('kind', 'parent')->sole()->embedding)->toBeNull()
            ->and(HelpChunk::where('kind', 'child')->count())->toBeGreaterThan(1);

        $this->artisan('help:ingest --strategy=heading --help-version=v1')
            ->expectsOutputToContain('Diff vs v1: 1 changed, 0 new,')
            ->assertSuccessful();

        expect(HelpChunk::count())->toBe(1)
            ->and(HelpChunk::sole()->embedding)->toHaveCount(768)
            ->and(HelpChunk::sole()->kind)->toBe('parent');
    });

    it('rejects an unknown strategy', function () {
        $this->artisan('help:ingest --strategy=semantic')->assertFailed();
    });

    it('adds the header to the keyword index as well', function () {
        $this->artisan('help:ingest --strategy=parent-child --context-headers')->assertSuccessful();

        $found = HelpChunk::where('kind', 'child')->whereRaw("search @@ websearch_to_tsquery('english', 'calculated')")->count();

        expect($found)->toBe(HelpChunk::where('kind', 'child')->count());              // "calculated" is only in the header of the second and third child
    });

    it('can ask the model for a sentence per chunk that situates it (the contextual retrieval mode)', function () {
        Http::fake([
            '*/chat/completions' => Http::response(chatBody('This part of the late fee article explains the proration rule.')),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        ]);

        $this->artisan('help:ingest --strategy=parent-child --context-summaries')->assertSuccessful();

        $child = HelpChunk::where('kind', 'child')->first();

        expect($child->context_header)->toBe('Late fees › How the fee is calculated — This part of the late fee article explains the proration rule.');

        $first = sentChatBodies()[0];

        expect($first['messages'][0]['content'])->toStartWith('<document>')->toContain('1.5 percent')
            ->and($first['messages'][1]['content'])->toContain('<chunk>');
    });

    it('survives a model that does not answer while writing the sentences', function () {
        Sleep::fake();

        Http::fake([
            '*/chat/completions' => Http::response('down', 500),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        ]);

        $this->artisan('help:ingest --strategy=parent-child --context-summaries')->assertSuccessful();

        expect(HelpChunk::where('kind', 'child')->first()->context_header)->toBe('Late fees › How the fee is calculated');
    });
});
