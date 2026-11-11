<?php

namespace App\Ai\Retrieval;

use App\Ai\Cache\HelpVersion;
use App\Ai\Embeddings\Embedder;
use App\Casts\Vector;
use App\Models\HelpChunk;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PgRetriever implements Retriever
{
    public const MODES = ['hybrid', 'vector', 'keyword'];

    public function __construct(
        private Embedder $embedder,
        private HelpVersion $version,
        private array $options = [],
    ) {}

    public static function fromConfig(Embedder $embedder, HelpVersion $version): self
    {
        return new self($embedder, $version, config('ai.retrieval', []));
    }

    public function with(array $options): self
    {
        return new self($this->embedder, $this->version, array_merge($this->options, $options));
    }

    public function withEmbedder(Embedder $embedder): self
    {
        return new self($embedder, $this->version, $this->options);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        if (trim($question) === '') {
            return [];
        }

        if (! HelpChunk::query()->where('help_version', $this->version->current())->exists()) {
            return [];                                                              // nothing ingested for this version: skip the embedding call
        }

        $mode = $this->mode();

        $vector = $mode === 'keyword'
            ? null
            : Vector::literal($this->embedder->query($question));

        $rows = $this->query($question, $vector, $scope, $mode, max($k, (int) $this->option('candidates', 20)));

        return collect($rows)
            ->map(fn (object $row) => $this->chunk($row))
            ->filter(fn (RetrievedChunk $chunk) => $this->passesFloor($chunk))
            ->unique('id')                                                          // two children of one section count once; the best-ranked one stays
            ->take($k)
            ->values()
            ->all();
    }

    private function mode(): string
    {
        $mode = (string) $this->option('mode', 'hybrid');

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown retrieval mode [{$mode}]. Use hybrid, vector or keyword.");
        }

        return $mode;
    }

    private function passesFloor(RetrievedChunk $chunk): bool
    {
        if ($chunk->keywordRank !== null) {
            return true;                                                            // an exact match on a word the customer typed is a strong signal on its own
        }

        return $chunk->similarity >= (float) $this->option('threshold', 0.70);
    }

    private function query(string $question, ?string $vector, RetrievalScope $scope, string $mode, int $limit): array
    {
        [$filter, $filterBindings] = $this->filter($scope);

        $pool = (int) $this->option('pool', 20);

        $rrfK = (int) $this->option('rrf_k', 60);

        $table = $this->table();

        $cast = 'halfvec('.(int) $this->option('dimensions', config('ai.embed_dimensions', 768)).')';

        $vectorSql = $mode === 'keyword'
            ? 'SELECT NULL::bigint AS id, NULL::float8 AS sim, NULL::bigint AS r WHERE false'
            : <<<SQL
                SELECT id,
                       1 - (embedding::{$cast} <=> ?::{$cast}) AS sim,
                       ROW_NUMBER() OVER (ORDER BY embedding::{$cast} <=> ?::{$cast}) AS r
                FROM {$table}
                WHERE embedding IS NOT NULL AND {$filter}
                ORDER BY embedding::{$cast} <=> ?::{$cast}
                LIMIT {$pool}
            SQL;

        $keywordSql = $mode === 'vector'
            ? 'SELECT NULL::bigint AS id, NULL::float8 AS rank, NULL::bigint AS r WHERE false'
            : <<<SQL
                SELECT id,
                       ts_rank(search, websearch_to_tsquery('english', ?)) AS rank,
                       ROW_NUMBER() OVER (ORDER BY ts_rank(search, websearch_to_tsquery('english', ?)) DESC) AS r
                FROM {$table}
                WHERE embedding IS NOT NULL AND {$filter} AND search @@ websearch_to_tsquery('english', ?)
                LIMIT {$pool}
            SQL;

        $bindings = [
            ...($mode === 'keyword' ? [] : [$vector, $vector, ...$filterBindings, $vector]),
            ...($mode === 'vector' ? [] : [$question, $question, ...$filterBindings, $question]),
        ];

        $sql = <<<SQL
            WITH vec AS ({$vectorSql}),
            kw AS ({$keywordSql})
            SELECT COALESCE(p.id, c.id) AS id, COALESCE(p.source, c.source) AS source, COALESCE(p.title, c.title) AS title,
                   COALESCE(p.heading_path, c.heading_path) AS heading_path, COALESCE(p.heading, c.heading) AS heading,
                   COALESCE(p.content, c.content) AS content, c.content AS matched, c.context_header,
                   c.help_version, COALESCE(p.chunk_key, c.chunk_key) AS chunk_key, c.tenant_id, c.tags,
                   vec.sim, kw.rank AS kw_rank,
                   COALESCE(1.0 / ({$rrfK} + vec.r), 0) + COALESCE(1.0 / ({$rrfK} + kw.r), 0) AS rrf
            FROM {$table} c
            LEFT JOIN {$table} p ON p.id = c.parent_id
            LEFT JOIN vec ON vec.id = c.id
            LEFT JOIN kw ON kw.id = c.id
            WHERE vec.id IS NOT NULL OR kw.id IS NOT NULL
            ORDER BY rrf DESC, c.id
            LIMIT {$limit}
        SQL;

        return DB::transaction(function () use ($sql, $bindings) {
            $this->tuneIndex();

            return DB::select($sql, $bindings);
        });
    }

    private function table(): string
    {
        $table = (string) $this->option('table', 'help_chunks');

        if (preg_match('/^(help_chunks|bench_[a-z0-9_]+)$/', $table) !== 1) {
            throw new InvalidArgumentException("Refusing to search table [{$table}].");
        }

        return $table;
    }

    private function filter(RetrievalScope $scope): array
    {
        $clauses = ['help_version = ?'];
        $bindings = [$this->version->current()];

        if ($scope->tenantId === null) {
            $clauses[] = 'tenant_id IS NULL';
        }

        if ($scope->tenantId !== null) {
            $clauses[] = '(tenant_id IS NULL OR tenant_id = ?)';
            $bindings[] = $scope->tenantId;
        }

        if ($scope->plan === null) {
            $clauses[] = "(tags->'plans') IS NULL";                                 // a scope that does not know the plan only sees passages that are not plan-restricted
        }

        if ($scope->plan !== null) {
            $clauses[] = "((tags->'plans') IS NULL OR jsonb_exists(tags->'plans', ?))";
            $bindings[] = $scope->plan;
        }

        if ($scope->area !== null) {
            $clauses[] = "tags->>'area' = ?";
            $bindings[] = $scope->area;
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    private function tuneIndex(): void
    {
        $efSearch = $this->option('ef_search');

        if ($efSearch === null) {
            return;
        }

        DB::statement('SET LOCAL hnsw.ef_search = '.(int) $efSearch);
    }

    private function chunk(object $row): RetrievedChunk
    {
        $tags = $row->tags === null
            ? []
            : json_decode((string) $row->tags, true);

        return new RetrievedChunk(
            id: (int) $row->id,
            source: $row->source,
            title: $row->title,
            headingPath: $row->heading_path,
            heading: $row->heading,
            content: $row->content,
            helpVersion: $row->help_version,
            similarity: (float) ($row->sim ?? 0),
            keywordRank: $row->kw_rank === null
                ? null
                : (float) $row->kw_rank,
            score: (float) $row->rrf,
            chunkKey: (string) $row->chunk_key,
            tenantId: $row->tenant_id === null
                ? null
                : (int) $row->tenant_id,
            plans: $tags['plans'] ?? null,
            matched: $row->matched === $row->content
                ? null
                : $this->matched($row),
        );
    }

    private function matched(object $row): string
    {
        return $row->context_header === null || $row->context_header === ''
            ? $row->matched
            : $row->context_header."\n\n".$row->matched;
    }
}
