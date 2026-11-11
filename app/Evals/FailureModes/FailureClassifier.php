<?php

namespace App\Evals\FailureModes;

use App\Evals\CaseResult;
use App\Evals\GateResult;
use App\Evals\Gates;

// Chapter 38, Topic 8: the checks the app already has, read in the order the book gives. Expected chunks that were not retrieved: retrieval. An expected decline: coverage. The facts were in the context and the answer lost them, or its structure was off: behavior and format. The facts were right and a computed number was not: arithmetic. Anything else is left for a judge.
final class FailureClassifier
{
    private const NUMBER = '/^\$?\d[\d,]*(\.\d+)?%?$/';

    private const ACCOUNT_FACT = '/^(INV-\d+|\$?\d[\d,]*(\.\d+)?%?)$/';

    public function classify(CaseResult $result, string $context, bool $usedTools = false): Failure
    {
        $case = $result->case;

        if ($result->error !== null || $result->answer === null) {
            return new Failure($case, FailureBucket::Unclear, 'no answer', (string) $result->error);
        }

        $reason = $result->report->describe();

        $failed = $this->qualityGates($result);

        if (isset($failed['expected_chunks'])) {
            return new Failure($case, FailureBucket::Retrieval, 'expected chunk not retrieved', $reason);
        }

        if ($case->expectedBehavior === 'decline' && $failed !== []) {
            return new Failure($case, FailureBucket::Coverage, 'the help center does not cover it', $reason);
        }

        if (isset($failed['behavior'])) {
            return $this->wrongAction($result, $reason);
        }

        if (isset($failed['required_facts'])) {
            return $this->missingFacts($result, $context, $usedTools, $reason);
        }

        if ($failed !== []) {
            return new Failure($case, FailureBucket::Behavior, Failure::WRONG_STRUCTURE, $reason);
        }

        return new Failure($case, FailureBucket::Unclear, 'failed a never-gate only', $reason);
    }

    /** @return array<string, GateResult> */
    private function qualityGates(CaseResult $result): array
    {
        $failed = [];

        foreach ($result->report->qualityFailures() as $gate) {
            $failed[$gate->gate] = $gate;
        }

        return $failed;
    }

    private function wrongAction(CaseResult $result, string $reason): Failure
    {
        $expected = $result->case->expectedBehavior;

        $actual = (string) $result->answer?->behavior;

        if ($expected === 'answer' && in_array($actual, ['escalate', 'decline', 'refuse'], true)) {
            return new Failure($result->case, FailureBucket::Behavior, Failure::WRONGFUL_ESCALATION, $reason);
        }

        if ($actual === 'answer') {
            return new Failure($result->case, FailureBucket::Behavior, Failure::MISSED_ESCALATION, $reason);
        }

        return new Failure($result->case, FailureBucket::Behavior, Failure::WRONG_STRUCTURE, $reason);
    }

    private function missingFacts(CaseResult $result, string $context, bool $usedTools, string $reason): Failure
    {
        $answer = Gates::plain((string) $result->answer?->answer);

        $missing = array_values(array_filter($result->case->mustInclude, fn (string $fact) => ! str_contains($answer, Gates::plain($fact))));

        $known = Gates::plain($context);

        $inContext = array_filter($missing, fn (string $fact) => str_contains($known, Gates::plain($fact)));

        if ($inContext !== []) {
            return new Failure($result->case, FailureBucket::Behavior, Failure::FACT_MISSING, $reason);
        }

        if ($this->allMatch($missing, self::ACCOUNT_FACT) && ! $usedTools) {
            return new Failure($result->case, FailureBucket::Behavior, Failure::TOOL_NOT_CALLED, $reason);        // the invoice number or amount lives in an account lookup the model never made: a habit to train, not a sum to fix
        }

        if ($this->allMatch($missing, self::NUMBER) && $this->hasAFigure($answer)) {
            return new Failure($result->case, FailureBucket::Arithmetic, 'the number the answer computed is not the right one', $reason);
        }

        return new Failure($result->case, FailureBucket::Unclear, 'the missing fact was not in the context', $reason);
    }

    private function allMatch(array $facts, string $pattern): bool
    {
        foreach ($facts as $fact) {
            if (preg_match($pattern, trim($fact)) !== 1) {
                return false;
            }
        }

        return $facts !== [];
    }

    private function hasAFigure(string $answer): bool
    {
        return preg_match('/\d+\.\d{1,2}|\d+%/', $answer) === 1;
    }
}
