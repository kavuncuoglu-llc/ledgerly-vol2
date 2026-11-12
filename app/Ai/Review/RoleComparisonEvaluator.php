<?php

namespace App\Ai\Review;

use App\Ai\AiManager;
use App\Evals\GoldenSet;
use App\Evals\RoleComparison\RoleComparison;
use App\Evals\RoleComparison\RoleResult;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

// Chapter 50, Topic 9: the Test stage's numbers. The candidate becomes a provider for the length of the run (a copy of the one it names, with its own model), is allowed to answer support.reply for that run only,
// and goes through the same gates as every other role (eval:compare's machinery) on a sample of the golden set that has every category in it.
final class RoleComparisonEvaluator implements CandidateEvaluator
{
    public function __construct(private RoleComparison $comparison, private GoldenSet $set, private AiManager $ai) {}

    public function evaluate(array $candidate, int $sample): CandidateResult
    {
        $name = (string) $candidate['name'];

        $model = (string) $candidate['model'];

        $replaces = (string) ($candidate['replaces'] ?? $candidate['from']);

        $from = (string) $candidate['from'];

        if (! is_array(config('ai.providers')[$from] ?? null)) {
            return new CandidateResult($name, $model, $replaces, skipped: "there is no provider [{$from}] to copy");
        }

        $provider = 'watch:'.$name;

        $previous = ['providers' => config('ai.providers'), 'features' => config('ai.features')];

        try {
            $this->register($provider, $from, $model);

            return $this->measure($candidate, $provider, $model, $replaces, $sample);
        } catch (Throwable $e) {
            return new CandidateResult($name, $model, $replaces, skipped: mb_substr($e->getMessage(), 0, 160));
        } finally {
            config(['ai.providers' => $previous['providers'], 'ai.features' => $previous['features']]);

            $this->ai->forget();
        }
    }

    private function register(string $provider, string $from, string $model): void
    {
        $providers = config('ai.providers');

        $providers[$provider] = array_merge($providers[$from], ['model' => $model, 'digest' => null]);

        $features = config('ai.features');

        $features['support.reply']['allowed_providers'][] = $provider;

        config(['ai.providers' => $providers, 'ai.features' => $features]);

        $this->ai->forget();
    }

    private function measure(array $candidate, string $provider, string $model, string $replaces, int $sample): CandidateResult
    {
        $cases = $this->balancedSample($sample);

        if ($cases->isEmpty()) {
            throw new InvalidArgumentException('the golden set is empty');
        }

        [$baseline, $result] = $this->comparison->run($cases, [$replaces, $provider], ['gates']);

        if ($result->skipped !== null) {
            return new CandidateResult((string) $candidate['name'], $model, $replaces, skipped: $result->skipped);
        }

        return new CandidateResult(
            name: (string) $candidate['name'],
            model: $model,
            replaces: $replaces,
            metrics: $result->metrics,
            baseline: $this->baselineMetrics($baseline),
            tripwire: filled($candidate['tripwire'] ?? null)
                ? 'configured: run eval:tripwire on its two directories'
                : 'not run: no lm_eval results for this candidate',
        );
    }

    // One case from each category in turn, so a sample of twelve has howto and billing and safety in it, not the first twelve of one file.
    private function balancedSample(int $size): Collection
    {
        $groups = $this->set->all()->groupBy('category')->map(fn (Collection $group) => $group->values());

        $picked = collect();

        for ($round = 0; $picked->count() < $size; $round++) {
            $before = $picked->count();

            foreach ($groups as $group) {
                if (isset($group[$round]) && $picked->count() < $size) {
                    $picked->push($group[$round]);
                }
            }

            if ($picked->count() === $before) {
                break;
            }
        }

        return $picked->values();
    }

    private function baselineMetrics(RoleResult $baseline): array
    {
        return $baseline->skipped === null
            ? $baseline->metrics
            : [];
    }
}
