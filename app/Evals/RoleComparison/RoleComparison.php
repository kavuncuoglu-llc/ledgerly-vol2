<?php

namespace App\Evals\RoleComparison;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\JudgePrompt;
use App\Ai\Routing\RoleEvaluator;
use App\Ai\Routing\Roles;
use App\Evals\CaseResult;
use App\Evals\GateRunner;
use App\Evals\GoldenCase;
use App\Evals\Injection\InjectionResult;
use App\Evals\Injection\InjectionRunner;
use App\Evals\Injection\InjectionSet;
use App\Models\ModelCall;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

// Chapter 41, Topic 6: the same cases, the same checks, one role at a time. Each role runs through the real pipeline (the support feature pointed at it, no escalation), so the gates, the judge and the usage log see what production would. `cascade` is the production route as it is configured: the router and the cascade on, nothing forced.
// Four families of check: gates (Chapter 29: pass rate, required facts, input tokens), judge (a different family from the model under test), injection (Chapter 32: model-level success) and tripwire (Chapter 30: forgetting; `tripwire:generation` for a hosted model, `tripwire:all` for one whose weights are local).
final class RoleComparison
{
    public const CHECKS = ['gates', 'judge', 'injection', 'tripwire:generation', 'tripwire:all'];

    public function __construct(
        private GateRunner $runner,
        private RoleEvaluator $evaluator,
        private InjectionRunner $injection,
        private TripwireRunner $tripwire,
        private AiManager $ai,
    ) {}

    /**
     * @param  Collection<int, GoldenCase>  $cases
     * @param  list<string>  $roles
     * @param  list<string>  $checks
     * @return list<RoleResult>
     */
    public function run(Collection $cases, array $roles, array $checks, ?Closure $progress = null): array
    {
        foreach ($checks as $check) {
            if (! in_array($check, self::CHECKS, true)) {
                throw new InvalidArgumentException("[{$check}] is not a check: ".implode(', ', self::CHECKS).'.');
            }
        }

        return array_map(fn (string $role) => $this->one($role, $cases, $checks, $progress), $roles);
    }

    private function one(string $role, Collection $cases, array $checks, ?Closure $progress): RoleResult
    {
        $provider = $role === 'cascade'
            ? $this->ai->providerFor('support.reply')
            : Roles::provider($role);

        if (! $this->ai->configured($provider)) {
            return new RoleResult($role, $provider, '', skipped: "{$provider} is not set up");
        }

        if (! $this->ai->mayReceive('support.reply', $provider)) {
            return new RoleResult($role, $provider, '', skipped: "support.reply may not send its data to {$provider}");
        }

        $result = new RoleResult($role, $provider, $this->ai->model($provider));

        $work = fn (Closure $inside) => $role === 'cascade'
            ? $this->withCascade($inside)
            : $this->evaluator->withOnlyRole($provider, $inside);

        $started = now();

        if (in_array('gates', $checks, true) || in_array('judge', $checks, true)) {
            $summary = $work(fn () => $this->runner->run($cases, false, $progress === null ? null : fn (CaseResult $r, int $done, int $total) => $progress($role, $done, $total)));

            $this->gates($result, $summary->results, $started);

            if (in_array('judge', $checks, true)) {
                $this->judge($result, $summary->results);
            }
        }

        if (in_array('injection', $checks, true)) {
            $this->injectionRate($result, $work);
        }

        foreach (['tripwire:generation' => 'generation', 'tripwire:all' => 'all'] as $check => $mode) {
            if (in_array($check, $checks, true)) {
                $this->forgetting($result, $role, $mode);
            }
        }

        return $result;
    }

    private function withCascade(Closure $work): mixed
    {
        $previous = config('ai.cascade.enabled');

        config(['ai.cascade.enabled' => true]);

        try {
            return $work();
        } finally {
            config(['ai.cascade.enabled' => $previous]);
        }
    }

    /** @param Collection<int, CaseResult> $results */
    private function gates(RoleResult $result, Collection $results, $started): void
    {
        $count = max(1, $results->count());

        $withFacts = $results->filter(fn (CaseResult $r) => $r->case->mustInclude !== []);

        $factsOk = $withFacts->filter(fn (CaseResult $r) => collect($r->report->results)->where('gate', 'required_facts')->every(fn ($gate) => $gate->passed));

        $result->metrics['cases'] = $results->count();

        $result->metrics['golden_pass_rate'] = round($results->filter(fn (CaseResult $r) => $r->passedQuality())->count() / $count, 4);

        $result->metrics['required_facts_rate'] = $withFacts->isEmpty()
            ? null
            : round($factsOk->count() / $withFacts->count(), 4);

        $result->metrics['never_failures'] = $results->reject(fn (CaseResult $r) => $r->passedNever())->count();

        $result->metrics['input_tokens_per_answer'] = (int) round($results->sum(fn (CaseResult $r) => $r->answer?->inputTokens ?? 0) / $count);

        $micros = (int) ModelCall::query()->where('created_at', '>=', $started)->where('feature', 'support.reply')->sum('cost_micros');

        $result->metrics['cost_per_answer_usd'] = round($micros / 1_000_000 / $count, 6);

        if ($micros === 0) {
            $result->notes['cost_per_answer_usd'] = 'no cost recorded: a local model, or a hosted model with no price yet';
        }
    }

    /** @param Collection<int, CaseResult> $results */
    private function judge(RoleResult $result, Collection $results): void
    {
        $provider = (string) config('ai.compare.judge_provider', 'local');

        $scores = [];

        foreach ($results->filter(fn (CaseResult $r) => $r->answer !== null) as $r) {
            try {
                $reply = $this->ai->chat($provider)->chat(ChatRequest::make(JudgePrompt::messages($r->case->question, $r->answer->answer, $r->case->expectedChunks), ['profile' => 'deterministic', 'max_tokens' => 4], 'compare.judge'));

                $score = JudgePrompt::parse((string) $reply->content);
            } catch (Throwable) {
                $score = null;
            }

            if ($score !== null) {
                $scores[] = $score;
            }
        }

        if ($scores === []) {
            $result->notes['judge_pass_rate'] = "the judge ({$provider}) gave no score";

            return;
        }

        $result->metrics['judge_pass_rate'] = round(count(array_filter($scores, fn (int $score) => $score >= 4)) / count($scores), 4);

        $result->notes['judge_pass_rate'] = "judge {$provider}, scores of 4 or 5 over ".count($scores).' answers';
    }

    private function injectionRate(RoleResult $result, Closure $work): void
    {
        $fixtures = InjectionSet::smoke(InjectionSet::load(base_path('tests/Fixtures/injection')), 2);

        $results = $work(fn () => $fixtures->map(fn ($fixture) => $this->injection->run($fixture)));

        $result->metrics['injection_model_success_rate'] = round($results->where('modelSuccess', true)->count() / max(1, $results->count()), 4);

        $result->metrics['injection_system_success'] = $results->where('systemSuccess', true)->count();

        $result->notes['injection_model_success_rate'] = $results->count().' fixtures (the smoke subset: two per carrier)';

        $errors = $results->filter(fn (InjectionResult $r) => $r->error !== null)->count();

        if ($errors > 0) {
            $result->notes['injection_errors'] = "{$errors} fixtures errored";
        }
    }

    private function forgetting(RoleResult $result, string $role, string $mode): void
    {
        try {
            $drop = $this->tripwire->largestDrop($role, $mode);
        } catch (Throwable $e) {
            $result->notes["tripwire_{$mode}"] = $e->getMessage();

            return;
        }

        $result->metrics["tripwire_{$mode}_largest_drop"] = $drop['drop'];

        $result->notes["tripwire_{$mode}"] = implode(', ', array_map(fn (string $task, float $change) => sprintf('%s %+.1f', $task, $change), array_keys($drop['tasks']), $drop['tasks']));
    }
}
