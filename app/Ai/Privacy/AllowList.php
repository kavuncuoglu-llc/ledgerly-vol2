<?php

namespace App\Ai\Privacy;

// The app's own identifier formats. Detection never touches them: an invoice number redacted as a phone number breaks the answer, because the model can no longer say which invoice is late.
final class AllowList
{
    private const BUILT_IN = [
        '/\bINV-\d{3,8}\b/i',                       // invoice numbers
        '/(?<![\w#])#\d{3,8}\b/',                   // "#4471"
        '/\bT-\d{3,8}\b/',                          // ticket ids
        '/\[[^\]\n]{1,80}\]/',                      // help-center labels the model cites: [Late fees > How the fee is calculated]
    ];

    public function __construct(private array $extraPatterns = [], private array $terms = []) {}

    public static function fromConfig(): self
    {
        return new self(
            extraPatterns: (array) config('ai.privacy.allow_patterns', []),
            terms: (array) config('ai.privacy.allow_terms', []),
        );
    }

    /**
     * @param  Span[]  $spans
     * @return Span[]
     */
    public function filter(string $text, array $spans): array
    {
        $protected = $this->protectedRanges($text);

        return array_values(array_filter($spans, fn (Span $span) => ! $this->isProtected($span, $protected)));
    }

    public function allowsTerm(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), array_map('mb_strtolower', $this->terms), true);
    }

    /** @return array<int, array{0: int, 1: int}> */
    private function protectedRanges(string $text): array
    {
        $ranges = [];

        foreach ([...self::BUILT_IN, ...$this->extraPatterns] as $pattern) {
            preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[0] as [$match, $offset]) {
                $ranges[] = [$offset, $offset + strlen($match)];
            }
        }

        return $ranges;
    }

    private function isProtected(Span $span, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($span->offset < $end && $start < $span->end()) {
                return true;
            }
        }

        return false;
    }
}
