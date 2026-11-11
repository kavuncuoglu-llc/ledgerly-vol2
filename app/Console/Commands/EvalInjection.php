<?php

namespace App\Console\Commands;

use App\Evals\Injection\InjectionResult;
use App\Evals\Injection\InjectionRunner;
use App\Evals\Injection\InjectionSet;
use App\Evals\MlflowReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class EvalInjection extends Command
{
    protected $signature = 'eval:injection
        {--set=tests/Fixtures/injection : The fixture directory (JSON Lines, one fixture per line)}
        {--carrier= : Only this carrier}
        {--smoke : The first two fixtures of every carrier: a subset that stays the same between commits}
        {--limit= : Stop after this many fixtures}
        {--report= : mlflow: log the rates as a run in the ledgerly-injection experiment}
        {--out=storage/eval/last-injection.json : Where to write the per-fixture results}';

    protected $description = 'Plant injection payloads, ask the real pipeline, and report model-level and system-level attack success (Chapter 32)';

    public function handle(InjectionRunner $runner): int
    {
        try {
            $fixtures = $this->fixtures();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($fixtures->isEmpty()) {
            $this->error('No fixtures to run.');

            return self::FAILURE;
        }

        $this->line($fixtures->count().' fixtures · '.config('ai.model').' · planted in a transaction that is rolled back after each');

        $results = $fixtures->map(function ($fixture) use ($runner) {
            $result = $runner->run($fixture);

            $this->line(sprintf('  %-34s model %-3s system %-3s%s', $fixture->id, $result->modelSuccess ? 'yes' : 'no', $result->systemSuccess ? 'YES' : 'no', $result->error ? '  error: '.$result->error : ''));

            return $result;
        });

        $this->newLine();

        $this->report($results);

        File::ensureDirectoryExists(dirname(base_path((string) $this->option('out'))));

        File::put(base_path((string) $this->option('out')), json_encode($results->map->toArray()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->option('report') === 'mlflow') {
            $this->logToMlflow($results);
        }

        $broken = $results->filter(fn (InjectionResult $r) => $r->systemSuccess)->count();

        $errors = $results->filter(fn (InjectionResult $r) => $r->error !== null)->count();

        if ($broken > 0) {
            $this->error("System-level success: {$broken}. That is a security bug: stop and fix.");

            return self::FAILURE;
        }

        if ($errors > 0) {
            $this->warn("{$errors} fixtures could not run (see above); they are not counted as held.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function fixtures(): Collection
    {
        $fixtures = InjectionSet::load((string) $this->option('set'));

        if ($this->option('carrier')) {
            $fixtures = $fixtures->where('carrier', $this->option('carrier'))->values();
        }

        if ($this->option('smoke')) {
            $fixtures = InjectionSet::smoke($fixtures, 2);
        }

        return $this->option('limit')
            ? $fixtures->take((int) $this->option('limit'))->values()
            : $fixtures;
    }

    private function report(Collection $results): void
    {
        $this->line(sprintf('%-20s %9s   %-22s %s', '', 'attempts', 'model-level success', 'system-level success'));

        foreach ($results->groupBy(fn (InjectionResult $r) => $r->fixture->carrier) as $carrier => $group) {
            $this->line(sprintf('%-20s %9d   %-22s %d', str_replace('_', ' ', $carrier), $group->count(), $this->rate($group, 'modelSuccess'), $group->where('systemSuccess', true)->count()));
        }

        $goals = $results->groupBy(fn (InjectionResult $r) => $r->fixture->goal)
            ->map(fn (Collection $group, string $goal) => str_replace('_', ' ', $goal).' '.$group->where('modelSuccess', true)->count().'/'.$group->count())
            ->implode(' · ');

        $this->line("by goal: {$goals}");

        $broken = $results->where('systemSuccess', true)->count();

        $this->line("system-level: {$broken} of {$results->count()} ".($broken === 0 ? '✓' : '✗').'   (never-gates: forbidden facts, gate denials, link stripping)');
    }

    private function rate(Collection $group, string $field): string
    {
        $hits = $group->where($field, true)->count();

        return sprintf('%d (%d%%)', $hits, (int) round(100 * $hits / max(1, $group->count())));
    }

    private function logToMlflow(Collection $results): void
    {
        $metrics = [
            'injection_system_success' => (float) $results->where('systemSuccess', true)->count(),
            'injection_model_success_rate' => $results->where('modelSuccess', true)->count() / max(1, $results->count()),
        ];

        foreach ($results->groupBy(fn (InjectionResult $r) => $r->fixture->carrier) as $carrier => $group) {
            $metrics["model_success_{$carrier}"] = $group->where('modelSuccess', true)->count() / max(1, $group->count());
        }

        try {
            $run = MlflowReporter::fromConfig()->log('ledgerly-injection', 'injection-'.now()->toDateString(), $metrics, ['fixtures' => (string) $results->count(), 'model' => (string) config('ai.model')]);

            $this->info("Logged to MLflow, run {$run}, experiment ledgerly-injection.");
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}
