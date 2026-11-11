<?php

namespace App\Console\Commands;

use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiCompareVectors extends Command
{
    protected $signature = 'ai:compare-vectors
        {--model= : The Hub repo of the shadow vectors (default: config ai.python.embed_model)}
        {--against=ollama : What the shadow vectors are compared with: the live help_chunks.embedding, made through Ollama}
        {--threshold=0.98 : A chunk below this cosine is listed as a disagreement}';

    protected $description = 'Cosine between each chunk\'s Python vector and its live vector, before anything is promoted (Chapter 27)';

    public function handle(): int
    {
        if ($this->option('against') !== 'ollama') {
            $this->error('--against=ollama is the only comparison: the live column is what help:ingest made through Ollama.');

            return self::FAILURE;
        }

        $model = (string) ($this->option('model') ?: config('ai.python.embed_model'));

        $rows = $this->cosines($model);

        if ($rows === []) {
            $this->error("No shadow vectors for {$model}. Run php artisan ai:reembed --via=python, with py:listen running.");

            return self::FAILURE;
        }

        $this->report($rows, $model, (float) $this->option('threshold'));

        return self::SUCCESS;
    }

    private function report(array $rows, string $model, float $threshold): void
    {
        $cosines = array_column($rows, 'cosine');

        sort($cosines);

        $below = array_filter($rows, fn (array $row) => $row['cosine'] < $threshold);

        $missing = $this->missing($model);

        $this->table(['shadow vs live', $model], [
            ['chunks compared', count($rows)],
            ['embedded chunks with no shadow vector', $missing],
            ['mean cosine', number_format(array_sum($cosines) / count($cosines), 6)],
            ['min cosine', number_format($cosines[0], 6)],
            ['5th percentile', number_format($cosines[(int) floor(0.05 * (count($cosines) - 1))], 6)],
            ['max cosine', number_format(end($cosines), 6)],
            ["below {$threshold}", count($below)],
        ]);

        usort($rows, fn (array $a, array $b) => $a['cosine'] <=> $b['cosine']);

        foreach (array_slice($rows, 0, 5) as $row) {
            $this->line(sprintf('  %.6f  #%d  %s › %s', $row['cosine'], $row['id'], $row['source'], $row['heading']));
        }

        $this->line($missing === 0 && $below === []
            ? 'Every embedded chunk has a shadow vector and none disagrees. Next: php artisan retrieval:eval --vectors=shadow'
            : 'Not ready to promote: fix the missing vectors or look at the lowest-scoring chunks first.');
    }

    private function cosines(string $model): array
    {
        return array_map(fn (object $row) => [
            'id' => (int) $row->id,
            'source' => $row->source,
            'heading' => $row->heading,
            'cosine' => (float) $row->cosine,
        ], DB::select(
            'SELECT c.id, c.source, c.heading, 1 - (c.embedding <=> v.embedding) AS cosine
             FROM help_chunk_vectors v
             JOIN help_chunks c ON c.id = v.help_chunk_id
             WHERE v.model = ? AND c.embedding IS NOT NULL
             ORDER BY c.id',
            [$model],
        ));
    }

    private function missing(string $model): int
    {
        return HelpChunk::query()
            ->whereNotNull('embedding')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('help_chunk_vectors')
                ->whereColumn('help_chunk_vectors.help_chunk_id', 'help_chunks.id')
                ->where('help_chunk_vectors.model', $model))
            ->count();
    }
}
