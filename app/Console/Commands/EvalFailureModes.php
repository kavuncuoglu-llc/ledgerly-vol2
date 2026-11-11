<?php

namespace App\Console\Commands;

use App\Ai\Routing\RoleEvaluator;
use App\Ai\Routing\Roles;
use App\Evals\CaseResult;
use App\Evals\EvalAnswer;
use App\Evals\FailureModes\Backlog;
use App\Evals\FailureModes\FailureAnalysis;
use App\Evals\FailureModes\FailureBucket;
use App\Evals\FailureModes\FailureJudge;
use App\Evals\FailureModes\FailureReport;
use App\Evals\GateRunner;
use App\Evals\Gates;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Facades\Ai;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class EvalFailureModes extends Command
{
    protected $signature = 'eval:failure-modes
        {--feature=support.reply : The feature whose failures are sorted}
        {--role=local : The role the golden cases are run on (a tier name or a provider)}
        {--category=billing : The golden-set category}
        {--since=30d : Production cases added in this window (Nd); human and synthetic cases always count}
        {--from= : Sort the failing cases in a file written by eval:gates instead of running the cases}
        {--limit= : Run at most this many cases (a quick look)}
        {--judge : Send what the checks cannot place to the judge (config ai.eval.judge_provider_local or the role under test)}
        {--backlog= : Where retrieval misses and coverage gaps are written (storage/eval/backlog.jsonl)}
        {--no-backlog : Do not write the backlog}
        {--save= : Write the per-case results here (storage/eval/last-failure-modes.json), to sort again with --from}
        {--json : Print the result as JSON}';

    protected $description = 'Sort the failing cases of a category by cause: retrieval, coverage, behavior and format, arithmetic, unclear (Chapter 38)';

    public function handle(GoldenSet $set, GateRunner $runner, RoleEvaluator $evaluator, FailureAnalysis $analysis): int
    {
        if ($this->option('feature') !== 'support.reply') {
            $this->error('Only support.reply has a golden set to sort: --feature=support.reply.');

            return self::FAILURE;
        }

        try {
            $cases = $this->cases($set);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($cases->isEmpty()) {
            $this->error('No golden cases in category '.$this->option('category').'.');

            return self::FAILURE;
        }

        $results = $this->option('from')
            ? $this->fromFile($cases)
            : $this->runCases($cases, $runner, $evaluator);

        $this->save($results);

        $report = $analysis->analyse($results, $this->judge());

        $this->print($report);

        $this->backlog($report);

        return self::SUCCESS;
    }

    /** @return Collection<int, GoldenCase> */
    private function cases(GoldenSet $set): Collection
    {
        $days = $this->days();

        $cases = $set->all()
            ->filter(fn (GoldenCase $case) => $case->category === $this->option('category'))
            ->reject(fn (GoldenCase $case) => $case->source === 'production' && $case->added < now()->subDays($days)->toDateString())
            ->values();

        if ($this->option('limit') !== null) {
            return $cases->take((int) $this->option('limit'))->values();
        }

        return $cases;
    }

    private function days(): int
    {
        if (preg_match('/^(\d+)d$/', (string) $this->option('since'), $match) !== 1) {
            throw new InvalidArgumentException('--since takes a number of days, such as 30d.');
        }

        return (int) $match[1];
    }

    /** @return Collection<int, CaseResult> */
    private function runCases(Collection $cases, GateRunner $runner, RoleEvaluator $evaluator): Collection
    {
        $provider = Roles::provider((string) $this->option('role'));

        $this->line($cases->count().' cases · role '.$this->option('role').' ('.$provider.', '.Ai::model($provider).')');

        $summary = $evaluator->withOnlyRole($provider, fn () => $runner->run($cases, false, fn (CaseResult $result, int $done, int $total) => $this->progress($result, $done, $total)));

        return $summary->results;
    }

    /** @return Collection<int, CaseResult> */
    private function fromFile(Collection $cases): Collection
    {
        $path = str_starts_with((string) $this->option('from'), '/')
            ? (string) $this->option('from')
            : base_path((string) $this->option('from'));

        if (! File::exists($path)) {
            throw new InvalidArgumentException("No results file at {$path}.");
        }

        $saved = collect(json_decode(File::get($path), true)['failing'] ?? []);

        return $saved
            ->map(fn (array $row) => $this->rebuild($row, $cases))
            ->filter()
            ->values();
    }

    private function rebuild(array $row, Collection $cases): ?CaseResult
    {
        $case = $cases->firstWhere('id', $row['id'] ?? null);

        if ($case === null) {
            return null;
        }

        if (! isset($row['answer'])) {
            return new CaseResult($case, null, Gates::evaluate('', [], $case), error: (string) ($row['reason'] ?? 'no answer'));
        }

        $answer = EvalAnswer::fromArray($row['answer']);

        return new CaseResult($case, $answer, Gates::evaluate($answer, $answer->retrieved, $case));
    }

    private function save(Collection $results): void
    {
        $path = $this->option('save') ?: 'storage/eval/last-failure-modes.json';

        $full = str_starts_with((string) $path, '/')
            ? (string) $path
            : base_path((string) $path);

        File::ensureDirectoryExists(dirname($full));

        File::put($full, json_encode(['ran_at' => now()->toIso8601String(), 'kind' => 'failure-modes', 'failing' => $results->reject(fn (CaseResult $result) => $result->passedQuality() && $result->passedNever())->map(fn (CaseResult $result) => $result->toArray())->values()->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function judge(): ?FailureJudge
    {
        if (! $this->option('judge')) {
            return null;
        }

        return new FailureJudge(Roles::provider((string) $this->option('role')));
    }

    private function progress(CaseResult $result, int $done, int $total): void
    {
        if (! $this->output->isVerbose()) {
            return;
        }

        $this->line(sprintf('  [%d/%d] %s %s', $done, $total, $result->passedQuality() && $result->passedNever() ? 'ok  ' : 'FAIL', $result->case->id));
    }

    private function print(FailureReport $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($report->total() === 0) {
            $this->info('No failing cases.');

            return;
        }

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        $this->line('Fine-tuning is for the first row only: '.round($report->share(FailureBucket::Behavior) * 100).'% of these failures.');
    }

    private function backlog(FailureReport $report): void
    {
        if ($this->option('no-backlog')) {
            return;
        }

        if ($report->total() === 0) {
            return;
        }

        $backlog = $this->option('backlog')
            ? new Backlog(str_starts_with((string) $this->option('backlog'), '/') ? (string) $this->option('backlog') : base_path((string) $this->option('backlog')))
            : Backlog::default();

        $count = $backlog->record($report);

        $this->line("backlog: {$count} cases (retrieval, coverage, to review) in ".$backlog->path());
    }
}
