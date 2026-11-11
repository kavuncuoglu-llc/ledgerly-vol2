<?php

namespace App\Ai\Ingestion;

use App\Ai\AiManager;
use App\Ai\Cache\HelpVersion;
use App\Ai\Embeddings\Chunk;
use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\MarkdownChunker;
use App\Ai\Tokens\TokenCounter;
use App\Help\HelpArticle;
use App\Models\HelpChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class HelpIngestor
{
    private const EMBED_BATCH = 48;

    public const STRATEGIES = ['heading', 'parent-child'];

    public const HEADERS = ['none', 'path', 'summary'];

    public function __construct(
        private MarkdownChunker $chunker,
        private Embedder $embedder,
        private BoilerplateStripper $stripper,
        private HelpVersion $versions,
        private TokenCounter $counter,
        private AiManager $ai,
    ) {}

    /** @return Collection<int, PreparedChunk> */
    public function prepare(Collection $articles, ?string $strategy = null, ?string $headers = null): Collection
    {
        $strategy = $this->strategy($strategy);
        $headers = $this->headers($headers);

        return $articles
            ->flatMap(fn (HelpArticle $article) => $this->prepareArticle($this->stripper->strip($article), $article, $strategy, $headers))
            ->values();
    }

    public function ingest(
        Collection $articles,
        string $version,
        bool $partial = false,
        bool $force = false,
        bool $dryRun = false,
        ?string $strategy = null,
        ?string $headers = null,
        bool $acceptChunkSizes = false,
    ): IngestReport {
        $started = microtime(true);

        $headers = $this->headers($headers);

        $prepared = $this->prepare($articles, $strategy, $headers);

        if (! $acceptChunkSizes && config('ai.ingest.chunk_gate.enabled', true)) {
            (new ChunkSizeGate)->check($prepared);                                     // Chapter 35: before anything is embedded or written
        }

        $existing = $this->existing($articles, $partial);

        $previous = $this->previousVersion($existing);

        if ($partial) {
            $version = $this->versions->current();                                   // one article alone must not split the library across two versions
        }

        $keys = $prepared->pluck('key')->all();

        $added = $prepared->reject(fn (PreparedChunk $p) => $existing->has($p->key));

        $changed = $prepared->filter(fn (PreparedChunk $p) => $existing->has($p->key) && $this->differs($existing[$p->key], $p, $headers));

        $removed = $existing->reject(fn (HelpChunk $row, $key) => in_array($key, $keys, true));

        $toEmbed = $prepared->filter(fn (PreparedChunk $p) => $p->embedded && $this->needsEmbedding($p, $existing, $added, $changed, $force));

        $embeddings = $dryRun
            ? []
            : $this->embedAll($toEmbed, $headers);

        if (! $dryRun) {
            DB::transaction(fn () => $this->write($prepared, $removed, $embeddings, $version, $partial));

            $this->versions->forget();
        }

        return new IngestReport(
            articles: $articles->count(),
            chunks: $prepared->count(),
            averageTokens: (int) round($prepared->where('kind', 'parent')->avg(fn (PreparedChunk $p) => $p->chunk->tokens) ?? 0),
            changed: $changed->count(),
            added: $added->count(),
            removed: $removed->count(),
            unchanged: $prepared->count() - $added->count() - $changed->count(),
            embedded: $toEmbed->count(),
            previousVersion: $previous,
            version: $version,
            seconds: microtime(true) - $started,
            parents: $prepared->where('kind', 'parent')->count(),
            children: $prepared->where('kind', 'child')->count(),
        );
    }

    private function strategy(?string $strategy): string
    {
        $strategy ??= (string) config('ai.retrieval.strategy', 'heading');

        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new InvalidArgumentException("Unknown chunk strategy [{$strategy}]. Use heading or parent-child.");
        }

        return $strategy;
    }

    private function headers(?string $headers): string
    {
        $headers ??= (string) config('ai.retrieval.context_headers', 'path');

        if (! in_array($headers, self::HEADERS, true)) {
            throw new InvalidArgumentException("Unknown context header mode [{$headers}]. Use none, path or summary.");
        }

        return $headers;
    }

    /** @return PreparedChunk[] */
    private function prepareArticle(HelpArticle $stripped, HelpArticle $original, string $strategy, string $headers): array
    {
        $prepared = [];

        foreach ($this->chunker->chunk($stripped) as $chunk) {
            $headingPath = Str::headline($original->category).MarkdownChunker::SEPARATOR.$chunk->heading;

            $children = $strategy === 'parent-child'
                ? (new ChildSplitter($this->counter, (int) config('ai.retrieval.child_tokens', 160)))->split($chunk)
                : [];

            $hasChildren = count($children) > 1;

            $parentKey = sha1($chunk->source.'|'.$headingPath.'|'.$chunk->position);

            $prepared[] = new PreparedChunk(
                chunk: $chunk,
                key: $parentKey,
                title: $original->title,
                headingPath: $headingPath,
                tags: $this->tags($original),
                modifiedAt: $original->updated,
                kind: 'parent',
                contextHeader: null,
                embedded: ! $hasChildren,                                           // a parent that has children is grounded on, not matched on
                articleBody: $stripped->body,
            );

            if (! $hasChildren) {
                continue;
            }

            foreach ($children as $index => $text) {
                $prepared[] = new PreparedChunk(
                    chunk: new Chunk($chunk->source, $chunk->position, $chunk->heading, $text, $this->counter->count($text)),
                    key: sha1($parentKey.'|child|'.$index),
                    title: $original->title,
                    headingPath: $headingPath,
                    tags: $this->tags($original),
                    modifiedAt: $original->updated,
                    kind: 'child',
                    parentKey: $parentKey,
                    contextHeader: $headers === 'none'
                        ? null
                        : $chunk->heading,                                          // "Title › Section": what a bare paragraph is about
                    embedded: true,
                    articleBody: $stripped->body,
                );
            }
        }

        return $prepared;
    }

    private function tags(HelpArticle $article): array
    {
        $declared = $article->frontMatter['tags'] ?? [];

        $tags = is_array($declared)
            ? $declared
            : [];

        if (isset($article->frontMatter['plans'])) {
            $tags['plans'] = (array) $article->frontMatter['plans'];
        }

        return ['area' => $article->category] + $tags;
    }

    private function existing(Collection $articles, bool $partial): Collection
    {
        $query = HelpChunk::query()->whereNull('tenant_id');

        if ($partial) {
            $query->whereIn('source', $articles->map(fn (HelpArticle $article) => $article->source())->all());
        }

        return $query->get()->keyBy(fn (HelpChunk $row) => $row->chunk_key ?? 'legacy-'.$row->id);
    }

    private function previousVersion(Collection $existing): ?string
    {
        $version = $existing->pluck('help_version')->filter()->last();

        return $version === null
            ? null
            : (string) $version;
    }

    private function differs(HelpChunk $row, PreparedChunk $prepared, string $headers): bool
    {
        if ($row->content !== $prepared->chunk->content) {
            return true;
        }

        if ($row->kind !== $prepared->kind) {
            return true;
        }

        if (($row->embedding === null) === $prepared->embedded) {
            return true;                                                            // a parent became matchable, or stopped being
        }

        if ($headers === 'summary') {
            return false;                                                           // a generated sentence is kept until the text changes or --force
        }

        return $row->context_header !== $prepared->contextHeader;
    }

    private function needsEmbedding(PreparedChunk $p, Collection $existing, Collection $added, Collection $changed, bool $force): bool
    {
        if ($force) {
            return true;
        }

        if ($added->contains('key', $p->key)) {
            return true;
        }

        if ($changed->contains('key', $p->key)) {
            return true;
        }

        return $existing[$p->key]->embedding === null;
    }

    /** @return array<string, array{vector: array<int, float>, header: ?string}> */
    private function embedAll(Collection $chunks, string $headers): array
    {
        $summaries = new ContextualizeChunks($this->ai, config('ai.retrieval.context_model'));

        $headersByKey = [];

        foreach ($chunks as $prepared) {
            $header = $prepared->contextHeader;

            if ($headers === 'summary') {
                $sentence = $summaries->summary($prepared->articleBody, $prepared->chunk->content);

                $header = trim(($header ?? '').($header !== null && $sentence !== '' ? ' — ' : '').$sentence) ?: null;
            }

            $headersByKey[$prepared->key] = $header;
        }

        $embeddings = [];

        foreach ($chunks->values()->chunk(self::EMBED_BATCH) as $batch) {                    // Chapter 18: 48 passages per request, not one
            $vectors = $this->embedder->documents($batch->map(fn (PreparedChunk $prepared) => $prepared->embedText($headersByKey[$prepared->key]))->all());

            foreach ($batch->values() as $position => $prepared) {
                $embeddings[$prepared->key] = ['vector' => $vectors[$position], 'header' => $headersByKey[$prepared->key]];
            }
        }

        return $embeddings;
    }

    private function write(Collection $prepared, Collection $removed, array $embeddings, string $version, bool $partial): void
    {
        foreach ($removed as $row) {
            $row->delete();
        }

        $ids = [];

        foreach ($prepared->sortBy(fn (PreparedChunk $p) => $p->kind === 'parent' ? 0 : 1) as $p) {
            $attributes = [
                'source' => $p->chunk->source,
                'position' => $p->chunk->position,
                'heading' => $p->chunk->heading,
                'content' => $p->chunk->content,
                'tokens' => $p->chunk->tokens,
                'title' => $p->title,
                'heading_path' => $p->headingPath,
                'tags' => $p->tags,
                'help_version' => $version,
                'source_modified_at' => $p->modifiedAt,
                'kind' => $p->kind,
                'parent_id' => $p->parentKey === null
                    ? null
                    : $ids[$p->parentKey],
            ];

            if (isset($embeddings[$p->key])) {
                $attributes['embedding'] = $embeddings[$p->key]['vector'];
                $attributes['context_header'] = $embeddings[$p->key]['header'];
            }

            if (! $p->embedded) {
                $attributes['embedding'] = null;
                $attributes['context_header'] = null;
            }

            $row = HelpChunk::query()->updateOrCreate(['chunk_key' => $p->key, 'tenant_id' => null], $attributes);

            $ids[$p->key] = $row->id;
        }

        if (! $partial) {
            HelpChunk::query()->whereNull('tenant_id')->where('help_version', '!=', $version)->update(['help_version' => $version]);
        }
    }
}
