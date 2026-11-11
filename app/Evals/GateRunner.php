<?php

namespace App\Evals;

use Closure;
use Illuminate\Support\Collection;
use Throwable;

// Chapter 29, Topic 5: golden questions through the whole pipeline (retrieval, routing, tools, citation checking), then the gates. eval:gates is this; promptfoo does the same over HTTP.
final class GateRunner
{
    public function __construct(private EvalAnswerer $answerer, private Similarity $similarity) {}

    /**
     * @param  Collection<int, GoldenCase>  $cases
     * @param  Closure(CaseResult, int, int): void|null  $progress
     */
    public function run(Collection $cases, bool $measureSimilarity = true, ?Closure $progress = null): GateSummary
    {
        $results = collect();

        foreach ($cases->values() as $index => $case) {
            $result = $this->one($case, $measureSimilarity);

            $results->push($result);

            if ($progress !== null) {
                $progress($result, $index + 1, $cases->count());
            }
        }

        return new GateSummary($results, (float) config('ai.eval.quality_threshold'), (int) config('ai.eval.never_allowed'));
    }

    public function one(GoldenCase $case, bool $measureSimilarity = true): CaseResult
    {
        try {
            $answer = $this->answerer->answer($case->customer, $case->question);
        } catch (Throwable $e) {
            return new CaseResult($case, null, Gates::evaluate('', [], $case), error: $e::class.': '.$e->getMessage());
        }

        $similarity = $measureSimilarity && $case->referenceAnswer !== ''
            ? $this->safeSimilarity($answer->answer, $case->referenceAnswer)
            : null;

        return new CaseResult($case, $answer, Gates::evaluate($answer, $answer->retrieved, $case, $similarity));
    }

    private function safeSimilarity(string $answer, string $reference): ?float
    {
        try {
            return $this->similarity->between($answer, $reference);
        } catch (Throwable) {
            return null;                                                           // the weakest gate must not be able to fail a run by being unavailable
        }
    }
}
