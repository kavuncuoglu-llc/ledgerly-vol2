<?php

namespace App\Ai\Training;

// Chapter 40, Topic 9 and Appendix I.2: the shapes of text aimed at a model rather than at a person. Chapter 32 defends the live request structurally (random-token tags, quarantine, guards); a training row has no such structure left, so this reads the text itself. It is deliberately broad: a flagged row goes to a reviewer, it is not deleted.
final class InjectionDetector
{
    private const PATTERNS = [
        'override' => '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(instructions?|rules|prompt|guidelines?|polic(y|ies))\b/i',
        'role-play' => '/\b(pretend (you are|to be)|you have no (rules|restrictions)|act as (an? )?(assistant|ai)[^.\n]{0,30}(no|without) (rules|restrictions))\b/i',
        'addressed to the assistant' => '/\b(note|message|instruction)s? (to|for) (the )?(ai |support )?assistant\b|(^|[\s.])assistant\s*:/i',
        'tool coercion' => '/\b(call|use|run|execute|invoke)\b\s+(the\s+)?(refund|issue_credit|retry_payment|change_billing_email|open_ticket|lookup_invoice)\b/i',
        'prompt extraction' => '/\b(repeat|reveal|print|show|summari[sz]e)\b[^.\n]{0,30}\b(system prompt|your instructions|your prompt)\b/i',
        'exfiltration' => '/!\[[^\]]*\]\(https?:\/\/|\b(send|post|forward)\b[^.\n]{0,30}\b(to|at)\b\s+https?:\/\//i',
        'tenant data' => '/\b(list|show|give|tell)\b[^.\n]{0,40}\b(every|all)\b[^.\n]{0,30}\b(other )?(customers?|invoices?|tenants?)\b/i',
    ];

    /** @return list<string> the names of the patterns the text matches */
    public function matches(string $text): array
    {
        $found = [];

        foreach (self::PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
