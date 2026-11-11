<?php

use App\Ai\Parsing\DocumentArticles;
use App\Ai\Parsing\DocumentParser;
use App\Ai\Parsing\DocxParser;
use App\Ai\Parsing\HtmlParser;
use App\Ai\Parsing\MarkdownParser;
use App\Ai\Parsing\ParserRouter;
use App\Ai\Parsing\ParsingFailed;
use App\Ai\Parsing\PdftotextParser;
use App\Ai\Parsing\SidecarPdfParser;
use App\Ai\Sidecar\SidecarClient;
use App\Models\HelpChunk;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\PhpWord;

function scratchDirectory(): string
{
    $directory = sys_get_temp_dir().'/ledgerly-docs-'.bin2hex(random_bytes(5));

    mkdir($directory, 0777, true);

    return $directory;
}

function writeDocx(string $path): void
{
    $word = new PhpWord;
    $word->addTitleStyle(1, ['bold' => true]);
    $word->addTitleStyle(2, ['bold' => true]);

    $section = $word->addSection();
    $section->addTitle('Late fee policy', 1);
    $section->addText('Ledgerly charges a fee on overdue invoices.');
    $section->addTitle('Rates', 2);

    $run = $section->addTextRun();
    $run->addText('The fee is ');
    $run->addText('1.5% per month');
    $run->addText('.');

    $section->addListItem('Starter');
    $section->addListItem('Pro');

    $table = $section->addTable();
    $table->addRow();
    $table->addCell(2000)->addText('Plan');
    $table->addCell(2000)->addText('Late fee');
    $table->addRow();
    $table->addCell(2000)->addText('Starter');
    $table->addCell(2000)->addText('1.5%');

    $word->save($path, 'Word2007');
}

function samplePdf(): string
{
    return base_path('storage/samples/clean.pdf');
}

describe('parsers', function () {
    it('reads Markdown as it is', function () {
        $path = scratchDirectory().'/a.md';

        file_put_contents($path, "# Title\n\n## Section\n\nText.\n");

        expect((new MarkdownParser)->parse($path))->toBe("# Title\n\n## Section\n\nText.\n");
    });

    it('turns HTML into Markdown with headings, lists and tables, and drops navigation, footers and scripts', function () {
        $html = <<<'HTML'
        <html><head><title>x</title><style>p{}</style><script>alert(1)</script></head><body>
        <nav><a href="/">Home</a><a href="/pricing">Pricing</a></nav>
        <main>
          <h1>Late fees</h1>
          <h2>How the fee is calculated</h2>
          <p>The fee is <strong>1.5%</strong> per month.</p>
          <ul><li>Starter</li><li>Pro</li></ul>
          <table><thead><tr><th>Plan</th><th>Fee</th></tr></thead><tbody><tr><td>Starter</td><td>1.5%</td></tr></tbody></table>
        </main>
        <footer>Copyright Ledgerly. Was this helpful?</footer>
        </body></html>
        HTML;

        $markdown = (new HtmlParser)->toMarkdown($html);

        expect($markdown)->toContain('# Late fees')
            ->toContain('## How the fee is calculated')
            ->toContain('**1.5%**')
            ->toContain('- Starter')
            ->toContain('| Plan | Fee |')
            ->not->toContain('Home')
            ->not->toContain('Copyright')
            ->not->toContain('alert');
    });

    it('turns a DOCX into Markdown: headings by style, a run, a list and a table', function () {
        $path = scratchDirectory().'/policy.docx';

        writeDocx($path);

        $markdown = (new DocxParser)->parse($path);

        expect($markdown)->toContain("# Late fee policy\n")
            ->toContain('## Rates')
            ->toContain('The fee is 1.5% per month.')
            ->toContain('- Starter')
            ->toContain('| Plan | Late fee |')
            ->toContain('|---|---|')
            ->toContain('| Starter | 1.5% |');
    });

    it('says so when a DOCX cannot be read', function () {
        $path = scratchDirectory().'/broken.docx';

        file_put_contents($path, 'not a zip');

        (new DocxParser)->parse($path);
    })->throws(ParsingFailed::class);

    it('reads a born-digital PDF with pdftotext when no sidecar is there', function () {
        $text = (new PdftotextParser)->parse(samplePdf());

        expect($text)->toContain('Northgate')->not->toContain("\f");
    })->skip(fn () => ! (new PdftotextParser)->available() || ! is_file(samplePdf()), 'pdftotext or the sample PDF is missing');

    it('sends a PDF to the sidecar and returns its Markdown', function () {
        Http::fake(['sidecar.test/parse' => Http::response(['markdown' => "# Invoice\n\n| Qty | Item |\n|---|---|\n| 2 | Paper |\n", 'parser' => 'marker'])]);

        $parser = new SidecarPdfParser(new SidecarClient('http://sidecar.test'), new PdftotextParser);

        $markdown = $parser->parse(samplePdf());

        expect($markdown)->toContain('| Qty | Item |');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://sidecar.test/parse' && $request->isMultipart());
    });

    it('falls back to pdftotext when the sidecar is down, and still gets the text', function () {
        Http::fake(['sidecar.test/parse' => fn () => throw new ConnectionException('Connection refused')]);

        $parser = new SidecarPdfParser(new SidecarClient('http://sidecar.test'), new PdftotextParser);

        expect($parser->parse(samplePdf()))->toContain('Northgate');
    })->skip(fn () => ! (new PdftotextParser)->available() || ! is_file(samplePdf()), 'pdftotext or the sample PDF is missing');

    it('fails with a clear message when there is no sidecar and no pdftotext', function () {
        Http::fake(['sidecar.test/parse' => fn () => throw new ConnectionException('Connection refused')]);

        $nothing = new class extends PdftotextParser
        {
            public function available(): bool
            {
                return false;
            }
        };

        (new SidecarPdfParser(new SidecarClient('http://sidecar.test'), $nothing))->parse(samplePdf());
    })->throws(ParsingFailed::class, 'not reachable');

    it('reports a PDF the sidecar rejected', function () {
        Http::fake(['sidecar.test/parse' => Http::response(['detail' => 'not a PDF'], 422)]);

        (new SidecarPdfParser(new SidecarClient('http://sidecar.test'), new PdftotextParser))->parse(samplePdf());
    })->throws(ParsingFailed::class, 'not a PDF');

    it('routes by extension and refuses what it cannot read', function () {
        $router = app(DocumentParser::class);

        expect($router)->toBeInstanceOf(ParserRouter::class)
            ->and($router->supports('pdf'))->toBeTrue()
            ->and($router->supports('DOCX'))->toBeTrue()
            ->and($router->supports('html'))->toBeTrue()
            ->and($router->supports('md'))->toBeTrue()
            ->and($router->supports('xlsx'))->toBeFalse();

        expect(fn () => $router->parse('/tmp/budget.xlsx'))->toThrow(ParsingFailed::class, 'No parser for .xlsx');
    });
});

describe('DocumentArticles and help:ingest --docs', function () {
    beforeEach(function () {
        $this->docs = scratchDirectory();

        mkdir($this->docs.'/policies');

        file_put_contents($this->docs.'/policies/terms-page.html', '<html><body><nav>menu</nav><main><h1>Terms</h1><h2>Late fees</h2><p>A late fee of 1.5 percent a month is charged after thirty days.</p></main></body></html>');
        writeDocx($this->docs.'/policies/late-fee-policy.docx');
        copy(base_path('storage/samples/clean.pdf'), $this->docs.'/policies/Northgate Invoice.pdf');
        file_put_contents($this->docs.'/policies/_draft.md', '# ignored');
        file_put_contents($this->docs.'/policies/notes.xlsx', 'ignored');

        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing')]);
    });

    afterEach(function () {
        File::deleteDirectory($this->docs);
    });

    it('turns each file into an article whose headings start at ##', function () {
        Http::fake(['sidecar.test/parse' => Http::response(['markdown' => "# Northgate Paper Co\n\n## Lines\n\n| Qty | Item |\n|---|---|\n| 2 | Paper |\n"])]);

        $articles = app(DocumentArticles::class)->from($this->docs)->keyBy->slug;

        expect($articles->keys()->all())->toBe(['late-fee-policy', 'northgate-invoice', 'terms-page'])
            ->and($articles['terms-page']->title)->toBe('Terms')
            ->and($articles['terms-page']->category)->toBe('policies')
            ->and($articles['terms-page']->source())->toBe('help/policies/terms-page.md')
            ->and($articles['terms-page']->body)->toContain('## Late fees')->not->toContain('# Terms')
            ->and($articles['late-fee-policy']->title)->toBe('Late fee policy')
            ->and($articles['late-fee-policy']->body)->toContain('## Rates')
            ->and($articles['northgate-invoice']->title)->toBe('Northgate Paper Co');
    });

    it('ingests parsed documents with the help center, as ordinary chunks', function () {
        Http::fake([
            'sidecar.test/parse' => Http::response(['markdown' => "# Northgate Paper Co\n\nInvoice NP-20931 totals 1,284.50.\n"]),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        ]);

        $this->artisan("help:ingest --docs={$this->docs}")
            ->expectsOutputToContain('Chunking 4 articles')
            ->assertSuccessful();

        expect(HelpChunk::query()->where('source', 'help/policies/terms-page.md')->value('content'))->toContain('1.5 percent')
            ->and(HelpChunk::query()->where('source', 'help/policies/northgate-invoice.md')->exists())->toBeTrue()
            ->and(HelpChunk::query()->where('source', 'like', 'help/policies/%')->pluck('tenant_id')->unique()->all())->toBe([null]);
    });

    it('does not let a document replace a Markdown article of the same name', function () {
        mkdir($this->docs.'/billing');

        file_put_contents($this->docs.'/billing/late-fees.html', '<main><h1>Impostor</h1><p>Replacement text.</p></main>');

        Http::fake([
            'sidecar.test/parse' => Http::response(['markdown' => "# Northgate\n\nText.\n"]),
            '*/embeddings' => fn (Request $request) => embeddingsResponse($request),
        ]);

        $this->artisan("help:ingest --docs={$this->docs}")->assertSuccessful();

        expect(HelpChunk::query()->where('source', 'help/billing/late-fees.md')->value('title'))->toBe('Late fees');
    });

    it('reports a document it cannot parse instead of half-ingesting', function () {
        file_put_contents($this->docs.'/policies/broken.docx', 'not a zip');

        Http::fake(['sidecar.test/parse' => Http::response(['markdown' => "# Northgate\n\nText.\n"])]);

        $this->artisan("help:ingest --docs={$this->docs}")->assertFailed();

        expect(HelpChunk::count())->toBe(0);
    });
});
