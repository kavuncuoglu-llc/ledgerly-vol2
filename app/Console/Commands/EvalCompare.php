<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Distillation\Categories;
use App\Ai\Embeddings\Embedder;
use App\Ai\Retrieval\Eval\PrecomputedEmbedder;
use App\Ai\Retrieval\Eval\QueryVectors;
use App\Ai\Retrieval\Eval\ShadowIndex;
use App\Ai\Retrieval\PgRetriever;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Evals\MlflowReporter;
use App\Evals\RoleComparison\FirstTokenProbe;
use App\Evals\RoleComparison\RoleComparison;
use App\Evals\RoleComparison\RoleResult;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class EvalCompare extends Command
{
    protected $signature = 'eval:compare
        {--set=golden:billing : golden, or golden:<category> (golden:billing is the billing slice)}
        {--roles=frontier,frontier-billing-ft,cascade : Roles to compare: a tier (local, mid, frontier), a provider name, or cascade (the production route as configured)}
        {--with=gates,judge,injection,tripwire:generation : Checks: gates, judge, injection, tripwire:generation, tripwire:all}
        {--drivers= : raw,prism,ai-sdk: the same role reached through each driver (the roles in ai.drivers.roles), for the Chapter 47 comparison; replaces --roles}
        {--metrics= : Only these rows: pass, facts, judge, input_tokens, cost, p50 (answer latency), ttft (time to first token)}
        {--categories= : Only the cases of these categories, as ai.distillation.categories names them (howto,plans,integrations), or the golden set\'s own (Chapter 46)}
        {--retrieval= : live,shadow: run every role once on the live vectors and once on the shadow vectors the Python worker made, to see whether a tuned embedding model helps end to end (Chapter 46)}
        {--retrieval-model= : The shadow vectors\' model (default config ai.python.embed_model)}
        {--limit= : Run at most this many cases (a quick look)}
        {--report= : mlflow: log one run per role in the ledgerly-compare experiment}
        {--log-to= : Log the one measured role\'s metrics into this existing MLflow run instead (a sweep run, for eval:sweep-report)}
        {--out=storage/eval/last-compare.json : Where the result is written (adr:append --from=eval:last reads it)}
        {--json : Print the result as JSON}';

    protected $description = 'Compare roles on the same golden cases with the same checks: quality, injection resistance, forgetting and cost per answer (Chapter 41)';

    public function handle(GoldenSet $set, RoleComparison $comparison): int
    {
        try {
            $cases = $this->cases($set);

            $roles = $this->roles();

            $this->onlyMetrics([]);

            $checks = $this->list('with');

            $progress = fn (string $role, int $done, int $total) => $this->output->isVerbose() ? $this->line("  {$role}: {$done}/{$total}") : null;

            $sources = $this->list('retrieval');

            $results = $sources === []
                ? $comparison->run($cases, $roles, $checks, $progress)
                : $this->acrossRetrieval($comparison, $cases, $roles, $checks, $sources, $progress);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $results = $this->forDrivers($results);

        $this->save($results, $cases, $checks);

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (RoleResult $r) => $r->toArray(), $results), JSON_PRETTY_PRINT));
        } else {
            $this->print($results, $cases);
        }

        if ($this->option('log-to') !== null) {
            $this->logTo((string) $this->option('log-to'), $results);
        } elseif ($this->option('report') === 'mlflow') {
            $this->mlflow($results);
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, GoldenCase> */
    private function cases(GoldenSet $set): Collection
    {
        $name = (string) $this->option('set');

        if ($name !== 'golden' && ! str_starts_with($name, 'golden:')) {
            throw new InvalidArgumentException('--set takes golden or golden:<category>, such as golden:billing.');
        }

        $cases = $set->all();

        if ($this->option('categories') !== null) {
            $wanted = $this->list('categories');

            $cases = $cases->filter(fn (GoldenCase $case) => $this->inCategories($case, $wanted))->values();
        }

        if (str_contains($name, ':') && $name !== 'golden:support') {                    // golden:support is the whole set: every case is a support reply
            $category = substr($name, strpos($name, ':') + 1);

            $cases = $cases->filter(fn (GoldenCase $case) => $case->category === $category)->values();
        }

        if ($cases->isEmpty()) {
            throw new InvalidArgumentException("No golden cases in {$name}.");
        }

        return $this->option('limit') === null
            ? $cases
            : $cases->take((int) $this->option('limit'))->values();
    }

    // A category is a golden category (`howto`), or an area of the help center that the case's expected passages belong to (`plans`).
    private function inCategories(GoldenCase $case, array $wanted): bool
    {
        foreach ($wanted as $category) {
            if ($case->category === $category) {
                return true;
            }

            foreach ($case->expectedChunks as $label) {
                if (config("ai.distillation.categories.{$category}") !== null && Categories::covers($category, Categories::pathOf((string) $label))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, GoldenCase>  $cases
     * @param  list<string>  $roles
     * @param  list<string>  $checks
     * @param  list<string>  $sources
     * @return list<RoleResult>
     */
    private function acrossRetrieval(RoleComparison $comparison, Collection $cases, array $roles, array $checks, array $sources, Closure $progress): array
    {
        foreach ($sources as $source) {
            if (! in_array($source, ['live', 'shadow'], true)) {
                throw new InvalidArgumentException('--retrieval takes live, shadow or live,shadow.');
            }
        }

        $results = [];

        foreach ($sources as $source) {
            $restore = $source === 'shadow'
                ? $this->useShadowVectors($cases)
                : fn () => null;

            try {
                foreach ($comparison->run($cases, $roles, $checks, $progress) as $result) {
                    $results[] = new RoleResult("{$result->role}@{$source}", $result->provider, $result->model, $result->metrics, $result->notes, $result->skipped);
                }
            } finally {
                $restore();
            }
        }

        return $results;
    }

    // The retriever reads the Python worker's vectors for the run, and the questions are embedded by the same model (the worker's, with its query prefix), so the only thing that differs from the live run is the embedding model.
    private function useShadowVectors(Collection $cases): Closure
    {
        $model = (string) ($this->option('retrieval-model') ?: config('ai.python.embed_model'));

        $table = 'bench_shadow_vectors';

        $built = app(ShadowIndex::class)->fromVectors($table, $model);

        if ($built['chunks'] === 0) {
            app(ShadowIndex::class)->drop($table);

            throw new InvalidArgumentException("No shadow vectors for {$model}: php artisan ai:reembed --model={$model} --into=shadow first.");
        }

        $vectors = app(QueryVectors::class)->embed($cases->map(fn (GoldenCase $case) => $case->question)->all(), $model);

        $before = config('ai.retrieval');

        config(['ai.retrieval.table' => $table, 'ai.retrieval.dimensions' => $built['dimensions']]);

        app()->instance(Embedder::class, new PrecomputedEmbedder(app(AiManager::class), $model, $vectors));

        app()->forgetInstance(PgRetriever::class);

        return function () use ($before, $table) {
            config(['ai.retrieval' => $before]);

            app()->forgetInstance(Embedder::class);

            app()->forgetInstance(PgRetriever::class);

            app(ShadowIndex::class)->drop($table);
        };
    }

    /** @return list<string> */
    private function roles(): array
    {
        $drivers = $this->list('drivers');

        if ($drivers === []) {
            return $this->list('roles');
        }

        $known = config('ai.drivers.roles', []);

        return array_map(fn (string $driver) => $known[$driver] ?? throw new InvalidArgumentException("[{$driver}] is not a driver: ".implode(', ', array_keys($known)).'.'), $drivers);
    }

    // Chapter 47: the columns are the drivers, so each result is named for its driver, and each gets the time to first token, which only a driver comparison asks for.
    /** @param list<RoleResult> $results @return list<RoleResult> */
    private function forDrivers(array $results): array
    {
        $drivers = $this->list('drivers');

        if ($drivers === []) {
            return $results;
        }

        $probe = app(FirstTokenProbe::class);

        foreach ($results as $index => $result) {
            $result->role = $drivers[$index];

            if ($result->skipped !== null) {
                continue;
            }

            $seconds = $probe->medianSeconds($result->provider, (int) config('ai.drivers.ttft_probes', 5));

            if ($seconds !== null) {
                $result->metrics['ttft_p50_s'] = $seconds;
            }
        }

        return $results;
    }

    /** @return list<string> */
    private function list(string $option): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option)))));
    }

    /** @param list<RoleResult> $results */
    private function save(array $results, Collection $cases, array $checks): void
    {
        $path = $this->absolute((string) $this->option('out'));

        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'set' => $this->option('set'),
            'cases' => $cases->count(),
            'checks' => $checks,
            'ran_on' => now()->toDateString(),
            'results' => array_map(fn (RoleResult $r) => $r->toArray(), $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @param list<RoleResult> $results */
    private function print(array $results, Collection $cases): void
    {
        $this->line("{$this->option('set')} · {$cases->count()} cases · ".count($results).' roles');

        $rows = [
            'Golden pass rate' => fn (RoleResult $r) => $this->percent($r->metrics['golden_pass_rate'] ?? null),
            'Required facts present' => fn (RoleResult $r) => $this->percent($r->metrics['required_facts_rate'] ?? null),
            'Judge pass rate' => fn (RoleResult $r) => $this->percent($r->metrics['judge_pass_rate'] ?? null),
            'Injection model-level success' => fn (RoleResult $r) => $this->percent($r->metrics['injection_model_success_rate'] ?? null),
            'Tripwire, generation tasks (largest drop)' => fn (RoleResult $r) => $this->drop($r->metrics['tripwire_generation_largest_drop'] ?? null),
            'Tripwire, all tasks (largest drop)' => fn (RoleResult $r) => $this->drop($r->metrics['tripwire_all_largest_drop'] ?? null),
            'Input tokens per answer' => fn (RoleResult $r) => isset($r->metrics['input_tokens_per_answer']) ? '~'.number_format($r->metrics['input_tokens_per_answer']) : '—',
            'Cost per answer' => fn (RoleResult $r) => isset($r->metrics['cost_per_answer_usd']) ? sprintf('$%.4f', $r->metrics['cost_per_answer_usd']) : '—',
            'Answer latency, p50' => fn (RoleResult $r) => isset($r->metrics['latency_p50_s']) ? sprintf('%.1f s', $r->metrics['latency_p50_s']) : '—',
            'Time to first token, p50' => fn (RoleResult $r) => isset($r->metrics['ttft_p50_s']) ? sprintf('%.3f s', $r->metrics['ttft_p50_s']) : '—',
        ];

        $rows = $this->onlyMetrics($rows);

        $this->table(
            ['', ...array_map(fn (RoleResult $r) => $r->skipped === null ? $r->role : "{$r->role} (skipped)", $results)],
            array_map(fn (string $label, callable $cell) => [$label, ...array_map(fn (RoleResult $r) => $r->skipped === null ? $cell($r) : '—', $results)], array_keys($rows), $rows),
        );

        foreach ($results as $result) {
            if ($result->skipped !== null) {
                $this->warn("  {$result->role}: not measured, {$result->skipped}");
            }

            foreach ($result->notes as $key => $note) {
                $this->line("  {$result->role} · {$key}: {$note}");
            }
        }
    }

    private const METRIC_ROWS = [
        'pass' => 'Golden pass rate',
        'facts' => 'Required facts present',
        'judge' => 'Judge pass rate',
        'input_tokens' => 'Input tokens per answer',
        'cost' => 'Cost per answer',
        'p50' => 'Answer latency, p50',
        'ttft' => 'Time to first token, p50',
    ];

    /** @param array<string, callable> $rows @return array<string, callable> */
    private function onlyMetrics(array $rows): array
    {
        $wanted = $this->list('metrics');

        if ($wanted === []) {
            return $rows;
        }

        $labels = array_map(fn (string $metric) => self::METRIC_ROWS[$metric] ?? throw new InvalidArgumentException("[{$metric}] is not a metric: ".implode(', ', array_keys(self::METRIC_ROWS)).'.'), $wanted);

        return array_intersect_key($rows, array_flip($labels));
    }

    private function percent(float|int|null $value): string
    {
        return $value === null
            ? '—'
            : round($value * 100).'%';
    }

    private function drop(float|int|null $value): string
    {
        return $value === null
            ? '—'
            : sprintf('%+.1f points', -$value);
    }

    /** @param list<RoleResult> $results */
    private function mlflow(array $results): void
    {
        foreach ($results as $result) {
            if ($result->skipped !== null) {
                continue;
            }

            try {
                $metrics = array_filter($result->metrics, fn ($value) => is_numeric($value));

                $run = MlflowReporter::fromConfig()->log('ledgerly-compare', "{$this->option('set')}-{$result->role}", $metrics, ['role' => $result->role, 'provider' => $result->provider, 'model' => $result->model, 'set' => (string) $this->option('set')]);

                $this->info("  logged {$result->role} to MLflow, run {$run}");
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        }
    }

    /** @param list<RoleResult> $results */
    private function logTo(string $runId, array $results): void
    {
        $measured = array_values(array_filter($results, fn (RoleResult $r) => $r->skipped === null));

        if (count($measured) !== 1) {
            $this->error('--log-to takes one measured role: '.count($measured).' were measured.');

            return;
        }

        $metrics = [];

        foreach (array_filter($measured[0]->metrics, fn ($value) => is_numeric($value)) as $key => $value) {
            $metrics[] = ['key' => $key, 'value' => (float) $value, 'step' => 0];
        }

        try {
            MlflowReporter::fromConfig()->metrics($runId, $metrics);

            $this->info("  logged {$measured[0]->role} to MLflow run {$runId}");
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
