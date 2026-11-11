<?php

namespace App\Ai\Embeddings;

use App\Casts\Vector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Nearest-neighbour search over help_chunks: the whole of retrieval at ch06 (Chapter 15 adds filters, keywords and a floor)
final class ChunkSearch
{
    public function __construct(private Embedder $embedder) {}

    public function search(string $question, int $limit = 5): Collection
    {
        return $this->nearest($this->embedder->query($question), $limit);
    }

    public function nearest(array $queryVector, int $limit = 5): Collection
    {
        $vectorLiteral = Vector::literal($queryVector);

        $rows = DB::select(<<<'SQL'
            SELECT id, source, heading, content, 1 - (embedding <=> ?::vector) AS similarity
            FROM help_chunks
            ORDER BY embedding <=> ?::vector
            LIMIT ?
        SQL, [$vectorLiteral, $vectorLiteral, $limit]);

        return collect($rows);
    }
}
