<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\Embedder;
use Illuminate\Console\Command;
use Throwable;

class AiCacheTune extends Command
{
    protected $signature = 'ai:cache-tune
        {--pairs=tests/Fixtures/cache/pairs.jsonl : Labeled pairs, one JSON object a line: {"a": "...", "b": "...", "same": true}}
        {--max-false-hit= : The most false hits you will accept, as a share of the different-answer pairs (default ai.semantic_cache.max_false_hit_rate)}
        {--from=0.80 : The lowest threshold to try}
        {--to=0.99 : The highest}';

    protected $description = 'Choose the semantic-cache similarity threshold from labeled question pairs: hit rate and precision at each threshold (Chapter 37)';

    public function handle(Embedder $embedder): int
    {
        $path = str_starts_with((string) $this->option('pairs'), '/')
            ? (string) $this->option('pairs')
            : base_path((string) $this->option('pairs'));

        if (! is_file($path)) {
            $this->error("The pairs file [{$this->option('pairs')}] does not exist.");

            return self::FAILURE;
        }

        $pairs = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->map(fn (string $line) => json_decode($line, true))->filter(fn ($row) => is_array($row) && isset($row['a'], $row['b'], $row['same']));

        if ($pairs->where('same', true)->isEmpty() || $pairs->where('same', false)->isEmpty()) {
            $this->error('The file needs pairs of both kinds: same-answer and different-answer.');

            return self::FAILURE;
        }

        try {
            $similarities = $pairs->map(fn (array $pair) => ['same' => (bool) $pair['same'], 'cosine' => $this->cosine($embedder->query($pair['a']), $embedder->query($pair['b']))])->all();
        } catch (Throwable $e) {
            $this->error('The embedding model did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        $limit = (float) ($this->option('max-false-hit') ?? config('ai.semantic_cache.max_false_hit_rate', 0.01));

        $table = [];

        $recommended = null;

        $same = count(array_filter($similarities, fn (array $row) => $row['same']));

        $different = count($similarities) - $same;

        for ($threshold = (float) $this->option('from'); $threshold <= (float) $this->option('to') + 1e-9; $threshold += 0.01) {
            $hits = array_filter($similarities, fn (array $row) => $row['cosine'] >= $threshold - 1e-9);

            $right = count(array_filter($hits, fn (array $row) => $row['same']));

            $wrong = count($hits) - $right;

            $row = ['threshold' => round($threshold, 2), 'hit_rate' => $right / $same, 'false_hit_rate' => $wrong / $different, 'precision' => count($hits) > 0 ? $right / count($hits) : 1.0];

            $table[] = $row;

            if ($recommended === null && $row['false_hit_rate'] <= $limit && $row['hit_rate'] > 0) {       // a threshold nothing reaches is not a cache
                $recommended = $row;
            }
        }

        $this->table(['threshold', 'hit rate (same-answer pairs)', 'false-hit rate (different-answer pairs)', 'precision of the hits'], array_map(fn (array $row) => [number_format($row['threshold'], 2), round($row['hit_rate'] * 100).'%', round($row['false_hit_rate'] * 100).'%', round($row['precision'] * 100).'%'], $table));

        $this->line(sprintf('%d same-answer pairs, %d different-answer pairs; cosine of same pairs %0.2f to %0.2f, of different pairs %0.2f to %0.2f', $same, $different, ...$this->range($similarities, true), ...$this->range($similarities, false)));

        if ($recommended === null) {
            $this->warn(sprintf('No threshold up to %s keeps false hits under %d%% and still hits: the look-alike pairs are as close as the rephrasings. Do not lower the bar; raise it, add pairs, or cache fewer categories.', number_format((float) $this->option('to'), 2), round($limit * 100)));

            return self::FAILURE;
        }

        $this->info(sprintf('recommended: AI_SEMANTIC_CACHE_THRESHOLD=%s (hit rate %d%%, false hits %d%%, precision %d%%). Currently %s.', number_format($recommended['threshold'], 2), round($recommended['hit_rate'] * 100), round($recommended['false_hit_rate'] * 100), round($recommended['precision'] * 100), number_format((float) config('ai.semantic_cache.threshold'), 2)));

        return self::SUCCESS;
    }

    /** @return array{0: float, 1: float} */
    private function range(array $similarities, bool $same): array
    {
        $values = array_column(array_filter($similarities, fn (array $row) => $row['same'] === $same), 'cosine');

        return [min($values), max($values)];
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $na += $value * $value;
            $nb += $b[$i] * $b[$i];
        }

        $norm = sqrt($na) * sqrt($nb);

        return $norm > 0
            ? $dot / $norm
            : 0.0;
    }
}
