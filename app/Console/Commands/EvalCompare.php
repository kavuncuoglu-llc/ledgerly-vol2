<?php

namespace App\Console\Commands;

use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Evals\MlflowReporter;
use App\Evals\RoleComparison\RoleComparison;
use App\Evals\RoleComparison\RoleResult;
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

            $roles = $this->list('roles');

            $checks = $this->list('with');

            $results = $comparison->run($cases, $roles, $checks, fn (string $role, int $done, int $total) => $this->output->isVerbose() ? $this->line("  {$role}: {$done}/{$total}") : null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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

        if (str_contains($name, ':')) {
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
        ];

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
