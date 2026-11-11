<?php

namespace App\Ai\Privacy;

// Chapter 34, Topic 5: a stream splits text into fragments, so a placeholder can arrive as "<EMA" and "IL_1>". Everything from a "<" that is not yet closed is held back until it can be told apart from ordinary text.
final class StreamRestorer
{
    private const MAX_PLACEHOLDER_LENGTH = 24;

    private string $buffer = '';

    public function __construct(private Vault $vault) {}

    public function feed(string $delta): string
    {
        $this->buffer .= $delta;

        $cut = $this->holdFrom($this->buffer);

        $ready = substr($this->buffer, 0, $cut);

        $this->buffer = substr($this->buffer, $cut);

        return $this->vault->restore($ready);
    }

    public function flush(): string
    {
        $rest = $this->buffer;

        $this->buffer = '';

        return $this->vault->restore($rest);
    }

    // The offset where the held-back tail starts: an open "<...", or an upper-case run that could still become "EMAIL_1".
    private function holdFrom(string $text): int
    {
        $open = strrpos($text, '<');

        if ($open !== false && ! str_contains(substr($text, $open), '>') && strlen($text) - $open < self::MAX_PLACEHOLDER_LENGTH) {
            return $open;
        }

        if (preg_match('/(?<![A-Za-z0-9_])([A-Z]{2,7}(?:_\d*)?)$/', $text, $m, PREG_OFFSET_CAPTURE) === 1 && $this->couldBecomePlaceholder($m[1][0])) {
            return $m[1][1];
        }

        return strlen($text);
    }

    private function couldBecomePlaceholder(string $tail): bool
    {
        $word = explode('_', $tail)[0];

        foreach (Vault::TYPES as $type) {
            if (str_starts_with($type, $word)) {
                return true;
            }
        }

        return false;
    }
}
