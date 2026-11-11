<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Cache\HelpVersion;
use App\Ai\Contracts\Reranker;
use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\EmbeddingFailed;
use App\Ai\Retrieval\Eval\EvalReport;
use App\Ai\Retrieval\Eval\GoldenSet;
use App\Ai\Retrieval\Eval\RetrievalEvaluator;
use App\Ai\Retrieval\Eval\ShadowIndex;
use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\RerankingRetriever;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class RetrievalEval extends Command
{
    protected $signature = 'retrieval:eval
        {--golden=storage/golden/retrieval.jsonl : JSON Lines: id, question, articles (category/slug), headings}
        {--mode= : hybrid, vector or keyword (default: config ai.retrieval.mode)}
        {--threshold= : Similarity floor for the vector side}
        {--reranker= : sidecar or none (default: config ai.retrieval.reranker)}
        {--rewrite= : off, rewrite, expand or hyde (default: config ai.retrieval.rewrite)}
        {--pool= : Candidates the reranker sees (default: config ai.retrieval.pool)}
        {--vectors=live : live, or shadow to run on the Python worker\'s help_chunk_vectors (Chapter 27)}
        {--vector-model= : The Hub repo of the shadow vectors (default: config ai.python.embed_model)}
        {--embed-model= : Compare this embedding model on the same chunks (needs --reindex-to)}
        {--reindex-to= : Copy help_chunks into this bench_* table and embed it with --embed-model first}
        {--keep-index : Do not drop the --reindex-to table afterwards}
        {--k=5 : Deepest cut-off; recall and precision are reported at 1, 3 and this}
        {--plan= : Evaluate as a customer on this plan (default: shared passages only)}
        {--no-store : Do not write the run to storage/golden/runs}';

    protected $description = 'Measure retrieval alone against a golden set: recall@k, precision@k, MRR and the empty-expected check';

    private ?string $vectorTable = null;

    private ?string $vectorModel = null;

    public function handle(RetrieverFactory $factory, HelpVersion $version, ShadowIndex $shadow): int
    {
        $this->vectorTable = null;

        $this->vectorModel = null;

        if (! HelpChunk::query()->exists()) {
            $this->error('help_chunks is empty. Run php artisan help:ingest first.');

            return self::FAILURE;
        }

        try {
            $questions = GoldenSet::load($this->path((string) $this->option('golden')));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! in_array($this->option('vectors'), ['live', 'shadow'], true)) {
            $this->error('--vectors takes live or shadow.');

            return self::FAILURE;
        }

        try {
            if ($this->option('vectors') === 'shadow') {
                return $this->evaluateShadowVectors($factory, $version, $shadow, $questions);
            }

            return $this->option('reindex-to')
                ? $this->compareEmbeddingModel($factory, $shadow, $questions)
                : $this->evaluate($factory, $version, $questions);
        } catch (ConnectionException|RequestException|InvalidArgumentException|EmbeddingFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function evaluateShadowVectors(RetrieverFactory $factory, HelpVersion $version, ShadowIndex $shadow, array $questions): int
    {
        $model = (string) ($this->option('vector-model') ?: config('ai.python.embed_model'));

        $table = 'bench_python_vectors';

        $built = $shadow->fromVectors($table, $model);

        if ($built['chunks'] === 0) {
            $shadow->drop($table);

            $this->error("No shadow vectors for {$model}. Run php artisan ai:reembed --via=python first (py:listen applies the results).");

            return self::FAILURE;
        }

        $this->vectorTable = $table;

        $this->vectorModel = $model;

        $this->line("Python vectors from {$model}: {$built['chunks']} chunk(s) copied into {$table}; questions are still embedded by ".config('ai.embed_model').'.');

        try {
            return $this->evaluate($factory, $version, $questions);
        } finally {
            $shadow->drop($table);
        }
    }

    private function evaluate(RetrieverFactory $factory, HelpVersion $version, array $questions): int
    {
        $overrides = $this->overrides();

        $retriever = $factory->make($overrides);

        $report = $this->measure($retriever, $questions);

        $this->line($this->configLine($factory->stageOne($overrides), $overrides, $version));

        $this->printTable($report);

        $this->line(sprintf('empty-expected correct:  %d/%d', $report->emptyCorrect, $report->emptyTotal));

        $this->line(sprintf('%d answerable questions · %.0f ms per query (including the embedding call and any rewrite or rerank)', $report->answerable, $report->milliseconds));

        $this->printMisses($report);

        if (! $this->option('no-store')) {
            $this->store($report, $factory->stageOne($overrides), $overrides);
        }

        return self::SUCCESS;
    }

    private function compareEmbeddingModel(RetrieverFactory $factory, ShadowIndex $shadow, array $questions): int
    {
        $model = (string) $this->option('embed-model');

        if ($model === '') {
            $this->error('--reindex-to needs --embed-model.');

            return self::FAILURE;
        }

        $table = (string) $this->option('reindex-to');

        $embedder = new Embedder(app(AiManager::class), $model, 0);

        $built = $shadow->build($table, $embedder);

        $stageOne = $factory->stageOne($this->overrides() + ['table' => $table, 'dimensions' => $built['dimensions']])->withEmbedder($embedder);

        $first = $this->measure($stageOne, $questions);

        $reranked = config('ai.retrieval.reranker') === 'none'
            ? null
            : $this->measure(new RerankingRetriever($stageOne, app(Reranker::class), (int) config('ai.retrieval.pool', 20)), $questions);

        $this->line(sprintf('%-22s %5s %20s %22s %16s %14s', 'model', 'dims', 'recall@5 (stage 1)', 'recall@5 (reranked)', 'embed '.$built['chunks'].' chunks', 'query latency'));

        $this->line(sprintf(
            '%-22s %5d %20.2f %22s %16s %11.0f ms',
            $model,
            $built['dimensions'],
            $first->recallAt($this->deepest()),
            $reranked === null ? 'n/a' : number_format($reranked->recallAt($this->deepest()), 2),
            round($built['seconds']).' s',
            $first->milliseconds,
        ));

        if (! $this->option('keep-index')) {
            $shadow->drop($table);
        }

        return self::SUCCESS;
    }

    private function measure(Retriever $retriever, array $questions): EvalReport
    {
        $k = $this->deepest();

        return (new RetrievalEvaluator($retriever))->run(
            $questions,
            new RetrievalScope(plan: $this->option('plan') ?: null),
            array_values(array_unique([1, min(3, $k), $k])),
        );
    }

    private function deepest(): int
    {
        return max(1, (int) $this->option('k'));
    }

    private function overrides(): array
    {
        return array_filter([
            'table' => $this->vectorTable,
            'mode' => $this->option('mode'),
            'threshold' => $this->option('threshold') === null
                ? null
                : (float) $this->option('threshold'),
            'reranker' => $this->option('reranker'),
            'rewrite' => $this->option('rewrite'),
            'pool' => $this->option('pool') === null
                ? null
                : (int) $this->option('pool'),
        ], fn ($value) => $value !== null);
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    private function directory(): string
    {
        return (string) config('ai.retrieval.eval_dir', storage_path('golden'));
    }

    private function configLine(PgRetriever $retriever, array $overrides, HelpVersion $version): string
    {
        $reranker = $overrides['reranker'] ?? config('ai.retrieval.reranker', 'none');

        $rewrite = $overrides['rewrite'] ?? config('ai.retrieval.rewrite', 'off');

        return sprintf(
            'config: %s · threshold %.2f · k %d · rrf %d · rerank %s · rewrite %s · %s · chunks by %s · help %s',
            $retriever->option('mode', 'hybrid'),
            $retriever->option('threshold', 0.70),
            $this->deepest(),
            $retriever->option('rrf_k', 60),
            $reranker === 'none' ? 'off' : $reranker.' (pool '.($overrides['pool'] ?? config('ai.retrieval.pool', 20)).')',
            $rewrite,
            config('ai.embed_model'),
            config('ai.retrieval.strategy', 'heading'),
            $version->current(),
        );
    }

    private function describe(array $run): string
    {
        $parts = [$run['mode'] ?? '?'];

        if (($run['reranker'] ?? 'none') !== 'none') {
            $parts[] = 'rerank';
        }

        if (($run['rewrite'] ?? 'off') !== 'off') {
            $parts[] = 'rewrite';
        }

        if (($run['strategy'] ?? 'heading') !== 'heading') {
            $parts[] = $run['strategy'];
        }

        return implode(' + ', $parts);
    }

    private function printTable(EvalReport $report): void
    {
        $this->newLine();
        $this->line(sprintf('%-8s %8s %10s %8s', '', 'recall', 'precision', 'MRR'));

        $deepest = max(array_keys($report->recall));

        foreach ($report->recall as $k => $recall) {
            $this->line(sprintf(
                '%-8s %8.2f %10.2f %8s',
                "@{$k}",
                $recall,
                $report->precision[$k],
                $k === $deepest
                    ? number_format($report->mrr, 2)
                    : '',
            ));
        }

        $this->newLine();
    }

    private function printMisses(EvalReport $report): void
    {
        if ($report->misses === []) {
            return;
        }

        $ids = implode(' ', array_map(fn (array $miss) => '#'.$miss['id'], array_slice($report->misses, 0, 12)));

        $file = $this->directory().'/misses-'.now()->toDateString().'.json';

        File::ensureDirectoryExists($this->directory());

        File::put($file, json_encode($report->misses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->line(sprintf('misses (%d): %s%s   → %s', count($report->misses), $ids, count($report->misses) > 12 ? ' ...' : '', str_replace(base_path().'/', '', $file)));
    }

    private function store(EvalReport $report, PgRetriever $retriever, array $overrides): void
    {
        $directory = $this->directory().'/runs';

        File::ensureDirectoryExists($directory);

        $mode = $retriever->option('mode', 'hybrid');

        $previous = collect(File::files($directory))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => json_decode(File::get($file->getPathname()), true))
            ->first();

        File::put($directory.'/'.now()->format('Y-m-d-His').'-'.$mode.'.json', json_encode([
            'mode' => $mode,
            'threshold' => $retriever->option('threshold', 0.70),
            'reranker' => $overrides['reranker'] ?? config('ai.retrieval.reranker', 'none'),
            'rewrite' => $overrides['rewrite'] ?? config('ai.retrieval.rewrite', 'off'),
            'strategy' => config('ai.retrieval.strategy', 'heading'),
            'embed_model' => $this->vectorModel ?? config('ai.embed_model'),
            'vectors' => $this->vectorTable === null
                ? 'live'
                : 'shadow',
            'ran_at' => now()->toIso8601String(),
        ] + $report->toArray(), JSON_PRETTY_PRINT)."\n");

        if ($previous === null) {
            return;
        }

        $deepest = max(array_keys($report->recall));

        $before = $previous['recall'][$deepest] ?? null;

        if ($before === null) {
            return;
        }

        $this->line(sprintf(
            'vs previous run (%s): recall@%d %.2f → %.2f   (%+.2f)',
            $this->describe($previous),
            $deepest,
            $before,
            $report->recallAt($deepest),
            $report->recallAt($deepest) - $before,
        ));
    }
}
