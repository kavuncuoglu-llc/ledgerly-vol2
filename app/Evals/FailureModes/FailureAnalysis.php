<?php

namespace App\Evals\FailureModes;

use App\Evals\CaseResult;
use Illuminate\Support\Collection;

// Chapter 38, Topic 8: every failing case, sorted by cause. A case that passes is not a failure and is never looked at; a case that fails only a never-gate is a safety defect, listed as unclear so that a person sees it.
final class FailureAnalysis
{
    public function __construct(
        private FailureClassifier $classifier,
        private FailureContext $context,
    ) {}

    /**
     * @param  Collection<int, CaseResult>  $results
     */
    public function analyse(Collection $results, ?FailureJudge $judge = null): FailureReport
    {
        $failures = $results
            ->reject(fn (CaseResult $result) => $result->passedQuality() && $result->passedNever())
            ->map(fn (CaseResult $result) => $this->one($result, $judge))
            ->values();

        return new FailureReport($failures);
    }

    private function one(CaseResult $result, ?FailureJudge $judge): Failure
    {
        $context = $this->context->of($result);

        $failure = $this->classifier->classify($result, $context, $this->context->usedTools($result));

        if ($failure->bucket !== FailureBucket::Unclear) {
            return $failure;
        }

        if ($judge === null) {
            return $failure;
        }

        $verdict = $judge->judge($result, $context);

        if ($verdict === null) {
            return new Failure($failure->case, FailureBucket::Unclear, $failure->detail, $failure->reason, judged: true);
        }

        if ($verdict === FailureBucket::Unclear) {
            return new Failure($failure->case, FailureBucket::Unclear, $failure->detail, $failure->reason, judged: true);
        }

        return new Failure($failure->case, $verdict, 'judged: '.$failure->detail, $failure->reason, judged: true);
    }
}
