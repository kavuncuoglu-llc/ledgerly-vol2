<?php

namespace App\Ai\Retrieval;

// Questions that carry an identifier should hit the keyword side verbatim; a rewrite can drop the number.
final class IdentifierPattern
{
    private const PATTERNS = [
        '/\binv(oice)?[-\s]?#?\d+\b/i',        // INV-4471, invoice 4471
        '/#\s?\d{3,}/',                         // #4471
        '/\bT-\d{3,}\b/',                       // T-10422
        '/\b\d{4,}\b/',                         // any long number: an id, an amount
        '/@\S+\.\S+/',                          // an email address
    ];

    public static function matches(string $question): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $question) === 1) {
                return true;
            }
        }

        return false;
    }
}
