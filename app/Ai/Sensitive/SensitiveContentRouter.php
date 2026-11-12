<?php

namespace App\Ai\Sensitive;

use Illuminate\Support\Str;

// Chapter 51, Topic 9: runs before the assistant on every message, and decides without asking it. Patterns first (they never forget a phrase the support team added), then, when enabled, a small local classifier that can only
// move a message towards a person, never away from one. Safety is read before scope: a message that is both goes to a person.
final class SensitiveContentRouter
{
    public function __construct(private SensitiveClassifier $classifier) {}

    public function route(string $message): RoutingDecision
    {
        $text = $this->readable($message);

        foreach ((array) config('sensitive-content.safety') as $kind => $patterns) {
            if ($this->matches($patterns, $text)) {
                return new RoutingDecision(RoutingDecision::SAFETY, $kind, RoutingDecision::PERSON_PRIORITY);
            }
        }

        if ($this->matches((array) config('sensitive-content.harassment'), $text)) {
            return new RoutingDecision(RoutingDecision::HARASSMENT, 'harassment', RoutingDecision::PERSON);
        }

        foreach ((array) config('sensitive-content.out_of_scope') as $kind => $patterns) {
            if ($this->matches($patterns, $text)) {
                return new RoutingDecision(RoutingDecision::OUT_OF_SCOPE, $kind, RoutingDecision::DECLINE_AND_PERSON);
            }
        }

        return $this->classifier->classify($message)
            ?? new RoutingDecision(RoutingDecision::IN_SCOPE, 'in_scope', RoutingDecision::ASSISTANT);
    }

    // Lower case, one apostrophe, one space, and the ordinary phrases that only look sensitive taken out.
    private function readable(string $message): string
    {
        $text = Str::lower(str_replace(['’', '‘'], "'", $message));

        $text = (string) preg_replace('/\s+/', ' ', $text);

        foreach ((array) config('sensitive-content.lookalikes') as $pattern) {
            $text = (string) preg_replace($pattern, ' ', $text);
        }

        return $text;
    }

    private function matches(array $patterns, string $text): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
