<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Retrieval\Eval\EvalReport;
use App\Ai\Retrieval\Eval\GoldenSet;
use App\Ai\Retrieval\Eval\PrecomputedEmbedder;
use App\Ai\Retrieval\Eval\QueryVectors;
use App\Ai\Retrieval\Eval\RetrievalEvaluator;
use App\Ai\Retrieval\Eval\ShadowIndex;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrieverFactory;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class EvalRetrieval extends Command
{
    protected $signature = 'eval:retrieval
        {--compare=live,shadow : live and shadow: the live column against the vectors the Python worker made (ai:reembed --into=shadow)}
        {--model= : The shadow vectors\' model: a Hub repo, or the name of a model tune_embeddings.py wrote (default config ai.python.embed_model)}
        {--golden=storage/golden/retrieval.jsonl : The questions and the articles that answer them}
        {--mode=vector : vector (default: only the embedding decides), hybrid or keyword: how both sides retrieve}
        {--threshold=0 : The similarity floor on both sides. The two models\' similarities sit on different scales, so a floor made for one cuts the other}
        {--k=5 : The deepest cut-off; recall is reported at 1, 3 and this}
        {--min-gain=0 : Exit 1 unless the shadow vectors gain at least this much recall at k and MRR over the live ones}';

    protected $description = 'Recall and rank on the golden questions for live against shadow embeddings: the check before ai:promote-vectors (Chapter 46, Topic 6)';

    public function handle(RetrieverFactory $factory, ShadowIndex $shadow, QueryVectors $queryVectors): int
    {
        if (array_map('trim', explode(',', (string) $this->option('compare'))) !== ['live', 'shadow']) {
            $this->error('--compare=live,shadow is the only comparison: the live column against the shadow vectors.');

            return self::FAILURE;
        }

        $model = (string) ($this->option('model') ?: config('ai.python.embed_model'));

        $table = 'bench_shadow_vectors';

        try {
            $questions = GoldenSet::load($this->path((string) $this->option('golden')));

            $built = $shadow->fromVectors($table, $model);

            if ($built['chunks'] === 0) {
                $this->error("No shadow vectors for {$model}. Run php artisan ai:reembed --model={$model} --into=shadow, with py:listen running.");

                return self::FAILURE;
            }

            $live = $this->measure($factory->stageOne($this->overrides()), $questions);

            $vectors = $queryVectors->embed(array_map(fn ($question) => $question->question, $questions), $model);

            $tuned = $this->measure($factory->stageOne($this->overrides() + ['table' => $table, 'dimensions' => $built['dimensions']])->withEmbedder(new PrecomputedEmbedder(app(AiManager::class), $model, $vectors)), $questions);
        } catch (InvalidArgumentException|Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $shadow->drop($table);
        }

        $this->print($live, $tuned, $model, $built['chunks']);

        $k = max(1, (int) $this->option('k'));

        $gain = $tuned->recallAt($k) - $live->recallAt($k);

        $gainMrr = $tuned->mrr - $live->mrr;

        return $gain >= (float) $this->option('min-gain') && $gainMrr >= (float) $this->option('min-gain')
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function print(EvalReport $live, EvalReport $shadow, string $model, int $chunks): void
    {
        $k = max(1, (int) $this->option('k'));

        $this->line("{$live->answerable} answerable questions · shadow vectors: {$model} ({$chunks} chunks), questions embedded by the same model");

        $this->line(str_pad('', 14).str_pad('live', 10).str_pad('shadow', 10).'change');

        foreach (array_values(array_unique([1, min(3, $k), $k])) as $cut) {
            $this->line($this->row("recall@{$cut}", $live->recallAt($cut), $shadow->recallAt($cut)));
        }

        $this->line($this->row('MRR', $live->mrr, $shadow->mrr));
    }

    private function overrides(): array
    {
        return ['mode' => (string) $this->option('mode'), 'threshold' => (float) $this->option('threshold')];
    }

    private function row(string $label, float $live, float $shadow): string
    {
        return str_pad($label, 14).str_pad(number_format($live, 3), 10).str_pad(number_format($shadow, 3), 10).sprintf('%+.3f', $shadow - $live);
    }

    private function measure($retriever, array $questions): EvalReport
    {
        $k = max(1, (int) $this->option('k'));

        return (new RetrievalEvaluator($retriever))->run($questions, new RetrievalScope, array_values(array_unique([1, min(3, $k), $k])));
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
