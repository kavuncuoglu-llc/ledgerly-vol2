<?php

namespace App\Ai\Prompts;

use InvalidArgumentException;

final class RewritePrompt
{
    public const VERSION = '2026-11-02.1';

    public const MODES = ['rewrite', 'expand', 'hyde'];

    public static function system(string $mode): string
    {
        return match ($mode) {
            'rewrite' => <<<'PROMPT'
            Restate the customer's question as a short phrase in the style of a help-center heading or first sentence, in documentation register. Keep every identifier (numbers, product names) verbatim. Output the phrase only.
            PROMPT,
            'expand' => <<<'PROMPT'
            Write three different short phrasings of the customer's question, in the words a help center would use. One per line, no numbering, no extra text. Keep every identifier (numbers, product names) verbatim.
            PROMPT,
            'hyde' => <<<'PROMPT'
            Write one short paragraph that a help-center article might contain as the answer to the customer's question, in documentation register. Do not mention that it is a guess. Keep every identifier (numbers, product names) verbatim. Output the paragraph only.
            PROMPT,
            default => throw new InvalidArgumentException("Unknown rewrite mode [{$mode}]. Use rewrite, expand or hyde."),
        };
    }

    public static function messages(string $mode, string $question): array
    {
        return [
            ['role' => 'system', 'content' => self::system($mode)],
            ['role' => 'user', 'content' => $question],                       // untrusted input: always a user turn
        ];
    }

    public static function maxTokens(string $mode): int
    {
        return match ($mode) {
            'rewrite' => 60,
            'expand' => 100,
            'hyde' => 150,
            default => throw new InvalidArgumentException("Unknown rewrite mode [{$mode}]."),
        };
    }
}
