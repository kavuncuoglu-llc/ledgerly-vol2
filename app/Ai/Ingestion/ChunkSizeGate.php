<?php

namespace App\Ai\Ingestion;

use App\Models\HelpChunk;
use Illuminate\Support\Collection;

// Chapter 35, Topic 4: Chapter 28's dataset_stats.py turned into a gate. An export with no headings makes every article one chunk of thousands of tokens, and the ingest job used to check that chunks had text, not that they were a sensible size.
// The reranker reads 512 tokens, so a share of chunks beyond that is a ranking problem as well as a cost one. A mean that jumps against what is already indexed means the chunker's input changed.
final class ChunkSizeGate
{
    private const MIN_INDEXED_FOR_BASELINE = 10;

    /** @param  Collection<int, PreparedChunk>  $prepared */
    public function check(Collection $prepared): void
    {
        $reasons = $this->reasons($this->sizes($prepared), $this->indexedMean());

        if ($reasons !== []) {
            throw ChunkSizeDrift::because($reasons);
        }
    }

    /** @param  int[]  $sizes */
    public function reasons(array $sizes, ?float $indexedMean): array
    {
        if ($sizes === []) {
            return [];
        }

        $reasons = [];

        $limit = (int) config('ai.ingest.chunk_gate.max_tokens', 512);

        $over = count(array_filter($sizes, fn (int $tokens) => $tokens > $limit)) / count($sizes);

        if ($over > (float) config('ai.ingest.chunk_gate.max_share_over', 0.05)) {
            $reasons[] = sprintf('%.0f%% of chunks are longer than %d tokens (the largest is %d)', $over * 100, $limit, max($sizes));
        }

        $mean = array_sum($sizes) / count($sizes);

        $shift = (float) config('ai.ingest.chunk_gate.max_mean_shift', 0.5);

        if ($indexedMean !== null && $indexedMean > 0 && abs($mean - $indexedMean) / $indexedMean > $shift) {
            $reasons[] = sprintf('the mean chunk went from %d to %d tokens', $indexedMean, $mean);
        }

        return $reasons;
    }

    /** @return int[] */
    private function sizes(Collection $prepared): array
    {
        return $prepared
            ->where('kind', 'parent')
            ->map(fn (PreparedChunk $p) => $p->chunk->tokens)
            ->values()
            ->all();
    }

    private function indexedMean(): ?float
    {
        $indexed = HelpChunk::query()->whereNull('tenant_id')->where('kind', 'parent');

        if ($indexed->count() < self::MIN_INDEXED_FOR_BASELINE) {
            return null;                                                             // a few chunks are not a distribution to compare against
        }

        return (float) $indexed->avg('tokens');
    }
}
