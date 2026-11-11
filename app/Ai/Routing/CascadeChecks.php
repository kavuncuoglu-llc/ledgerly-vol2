<?php

namespace App\Ai\Routing;

use App\Ai\Contracts\FinishReason;
use App\Ai\Prompts\SupportPrompt;

// Chapter 37, Topic 3: the escalation signal is never the model's own opinion of its answer. It is the checks the app already trusts on every answer: the citation check passes (after its one repair), the answer guard
// (the never-gates) does not block, the answer was not cut off, and the model did not decline a question its sources answer.
final class CascadeChecks
{
    /** @return string[] the names of the checks the attempt failed; empty means it passed */
    public function failures(AttemptResult $attempt, float $bestScore): array
    {
        $failed = [];

        if ($attempt->blocked) {
            $failed[] = 'never_gate';
        }

        if ($attempt->result->response?->finishReason === FinishReason::Length) {
            $failed[] = 'truncated';
        }

        if ($attempt->result->citations?->hasErrors()) {
            $failed[] = 'citations';
        }

        if ($failed === [] && $this->wronglyDeclined($attempt, $bestScore)) {
            $failed[] = 'wrongful_decline';                                          // not when the answer is the app's own escape hatch after a failed citation check: that failure is already named
        }

        if (trim($attempt->result->answer()) === '') {
            $failed[] = 'empty';
        }

        return $failed;
    }

    // The model said the help center does not cover it, yet retrieval found a passage that does: the answer is the model's mistake, not the help center's gap.
    private function wronglyDeclined(AttemptResult $attempt, float $bestScore): bool
    {
        if ($attempt->prompt->labelMap === []) {
            return false;
        }

        if ($bestScore < (float) config('ai.cascade.wrongful_decline_above', 0.60)) {
            return false;
        }

        $answer = mb_strtolower($attempt->result->answer());

        if ($answer === mb_strtolower(SupportPrompt::escapeHatch())) {
            return true;
        }

        foreach (config('ai.eval.decline_phrases', []) as $phrase) {
            if (str_contains($answer, mb_strtolower((string) $phrase))) {
                return true;
            }
        }

        return false;
    }
}
