<?php

namespace App\Console\Commands;

use App\Ai\Cache\HelpVersion;
use App\Ai\Embeddings\EmbeddingFailed;
use App\Ai\Ingestion\BoilerplateStripper;
use App\Ai\Ingestion\ChunkSizeDrift;
use App\Ai\Ingestion\HelpIngestor;
use App\Ai\Parsing\DocumentArticles;
use App\Ai\Parsing\ParsingFailed;
use App\Help\HelpArticle;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class HelpIngest extends Command
{
    protected $signature = 'help:ingest
        {--help-version= : The help_version to stamp on every chunk (a docs commit hash); default is LEDGERLY_HELP_VERSION or a hash of the articles}
        {--docs= : A directory of <category>/<file>.pdf|docx|html|md to parse and ingest with the help center (default config ai.retrieval.extra_sources)}
        {--article= : Only this article, as category/slug (keeps the current help_version)}
        {--strategy= : heading (one chunk per section) or parent-child (match small children, ground on the section); default config ai.retrieval.strategy}
        {--context-headers : Prepend the title and heading path to each child before embedding it (no model call)}
        {--context-summaries : Also ask the model for a sentence per chunk that situates it in its article}
        {--accept-chunk-sizes : Write the chunks even though their sizes moved (the Chapter 35 gate: too many chunks over 512 tokens, or a mean far from what is indexed)}
        {--force : Embed every chunk again, for example after changing AI_EMBED_MODEL}
        {--dry-run : Chunk and diff against help_chunks without calling the model or writing}';

    protected $description = 'Strip boilerplate, chunk the help center and upsert only the chunks that changed, with metadata, a keyword index and a help_version';

    public function handle(HelpIngestor $ingestor, HelpVersion $versions, BoilerplateStripper $stripper): int
    {
        try {
            $articles = $this->articles();
        } catch (ParsingFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($articles->isEmpty()) {
            $this->error('No help articles found in '.HelpArticle::basePath());

            return self::FAILURE;
        }

        $version = (string) ($this->option('help-version') ?: config('ledgerly.help_version') ?: $versions->ofArticles());

        $this->line("Stripping boilerplate ({$stripper->count()} patterns)...");

        try {
            $report = $ingestor->ingest(
                articles: $articles,
                version: substr($version, 0, 40),
                partial: (bool) $this->option('article'),
                force: (bool) $this->option('force'),
                dryRun: (bool) $this->option('dry-run'),
                strategy: $this->option('strategy') ?: null,
                headers: $this->headerMode(),
                acceptChunkSizes: (bool) $this->option('accept-chunk-sizes'),
            );
        } catch (ConnectionException|RequestException|EmbeddingFailed|InvalidArgumentException|ChunkSizeDrift $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Chunking %d articles... %d chunks (avg %d tokens)', $report->articles, $report->chunks, $report->averageTokens));

        if ($report->children > 0) {
            $this->line(sprintf('Parent-child: %d sections, %d children to match on', $report->parents, $report->children));
        }

        $this->line(sprintf(
            'Diff vs %s: %d changed, %d new, %d removed, %d unchanged',
            $report->previousVersion ?? 'nothing',
            $report->changed,
            $report->added,
            $report->removed,
            $report->unchanged,
        ));

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was embedded or written.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Embedded %d chunks with %s (%.1fs)', $report->embedded, config('ai.embed_model'), $report->seconds));

        $this->line("Upserted. help_version = {$report->version}");

        return self::SUCCESS;
    }

    private function documents(): Collection
    {
        $directory = $this->option('docs') ?: config('ai.retrieval.extra_sources');

        if (! $directory) {
            return collect();
        }

        $taken = HelpArticle::all()->map(fn (HelpArticle $article) => $article->source())->all();

        return app(DocumentArticles::class)
            ->from((string) $directory)
            ->reject(fn (HelpArticle $article) => in_array($article->source(), $taken, true));      // a Markdown article of the same name wins
    }

    private function headerMode(): ?string
    {
        if ($this->option('context-summaries')) {
            return 'summary';
        }

        return $this->option('context-headers')
            ? 'path'
            : null;
    }

    private function articles(): Collection
    {
        $only = $this->option('article');

        if (! $only) {
            return HelpArticle::all()->concat($this->documents())->values();
        }

        [$category, $slug] = array_pad(explode('/', $only, 2), 2, '');

        $article = HelpArticle::find($category, $slug);

        return $article
            ? collect([$article])
            : collect();
    }
}
