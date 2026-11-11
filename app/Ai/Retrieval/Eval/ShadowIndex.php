<?php

namespace App\Ai\Retrieval\Eval;

use App\Ai\Embeddings\Embedder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

// A throwaway copy of help_chunks embedded with another model, so candidate embedding models can be compared on the same chunks (Figure 16-6).
final class ShadowIndex
{
    public const COPIED = [
        'id', 'source', 'position', 'heading', 'content', 'tokens', 'created_at', 'updated_at', 'title', 'heading_path', 'tags',
        'help_version', 'source_modified_at', 'chunk_key', 'tenant_id', 'parent_id', 'kind', 'context_header',
    ];

    /** @return array{dimensions: int, chunks: int, seconds: float} */
    public function build(string $table, Embedder $embedder): array
    {
        $this->assertName($table);

        $started = microtime(true);

        DB::statement("DROP TABLE IF EXISTS {$table}");
        DB::statement("CREATE TABLE {$table} (LIKE help_chunks INCLUDING DEFAULTS INCLUDING GENERATED)");

        $columns = implode(', ', self::COPIED);

        DB::statement("INSERT INTO {$table} ({$columns}) SELECT {$columns} FROM help_chunks WHERE tenant_id IS NULL AND embedding IS NOT NULL");

        $dimensions = 0;
        $count = 0;

        foreach (DB::table($table)->orderBy('id')->get(['id', 'content', 'context_header']) as $row) {
            $text = $row->context_header
                ? $row->context_header."\n\n".$row->content
                : $row->content;

            $vector = $embedder->document($text);

            if ($dimensions === 0) {
                $dimensions = count($vector);

                DB::statement("ALTER TABLE {$table} ALTER COLUMN embedding TYPE vector({$dimensions}) USING NULL");
            }

            DB::statement("UPDATE {$table} SET embedding = ?::vector WHERE id = ?", ['['.implode(',', $vector).']', $row->id]);

            $count++;
        }

        return ['dimensions' => $dimensions, 'chunks' => $count, 'seconds' => microtime(true) - $started];
    }

    /**
     * The same copy, with the vectors the Python worker made (help_chunk_vectors) in place of the live ones (Chapter 27), so retrieval:eval --vectors=shadow can run the golden set on them.
     *
     * @return array{dimensions: int, chunks: int}
     */
    public function fromVectors(string $table, string $model): array
    {
        $this->assertName($table);

        DB::statement("DROP TABLE IF EXISTS {$table}");
        DB::statement("CREATE TABLE {$table} (LIKE help_chunks INCLUDING DEFAULTS INCLUDING GENERATED)");

        $columns = implode(', ', self::COPIED);

        $fromColumns = implode(', ', array_map(fn (string $column) => "h.{$column}", self::COPIED));

        $chunks = DB::affectingStatement(
            "INSERT INTO {$table} ({$columns}, embedding) SELECT {$fromColumns}, v.embedding FROM help_chunks h JOIN help_chunk_vectors v ON v.help_chunk_id = h.id AND v.model = ? WHERE h.tenant_id IS NULL AND h.embedding IS NOT NULL",
            [$model],
        );

        return ['dimensions' => (int) config('ai.embed_dimensions'), 'chunks' => $chunks];
    }

    public function drop(string $table): void
    {
        $this->assertName($table);

        DB::statement("DROP TABLE IF EXISTS {$table}");
    }

    private function assertName(string $table): void
    {
        if (preg_match('/^bench_[a-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('--reindex-to takes a table name that starts with bench_, for example bench_bge.');
        }
    }
}
