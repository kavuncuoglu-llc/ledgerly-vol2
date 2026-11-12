<?php

namespace App\Ai\Sensitive;

use App\Evals\EvalBehavior;

// Chapter 51, Topic 9: after the assistant answers, was an in-scope question declined? A refusal of something the router had already sent to a person is not a false refusal; a refusal of an ordinary question is.
final class RefusalDetector
{
    public function refused(string $answer): bool
    {
        return in_array(EvalBehavior::classify($answer), ['refuse', 'decline'], true);
    }

    public function isFalseRefusal(RoutingDecision $decision, string $answer): bool
    {
        return $decision->category === RoutingDecision::IN_SCOPE
            && $this->refused($answer);
    }
}
