<?php

namespace App\Ai\Review;

use Illuminate\Support\Str;

// Chapter 51, Topic 8: a reviewer shown a fluent draft tends to approve it. The review screen draws attention to where errors hide: sentences that state something without a citation, and every figure.
final class DraftHighlighter
{
    private const FIGURE = '/(?:[$€£]\s?\d+(?:[.,]\d+)*|\b\d+(?:[.,]\d+)*\s?%|\bINV-\d+\b|#\d{3,}|\b\d{4}-\d{2}-\d{2}\b|\b\d+(?:[.,]\d+)*\b)/u';

    private const CITATION = '/\[[^\]]{3,}\]/';

    private const SOCIAL = '/^(hi|hello|hey|thanks|thank you|dear|best|regards|kind regards|sorry|i\'m sorry|i am sorry|happy to help)\b/i';

    /** @return list<array{text: string, uncited: bool, figures: list<string>}> */
    public function sentences(string $draft): array
    {
        $out = [];

        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', trim($draft), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            $sentence = trim($sentence);

            preg_match_all(self::FIGURE, $sentence, $figures);

            $out[] = [
                'text' => $sentence,
                'uncited' => $this->statesSomething($sentence) && preg_match(self::CITATION, $sentence) !== 1,
                'figures' => array_values(array_unique($figures[0])),
            ];
        }

        return $out;
    }

    public function needsAttention(string $draft): bool
    {
        foreach ($this->sentences($draft) as $sentence) {
            if ($sentence['uncited'] || $sentence['figures'] !== []) {
                return true;
            }
        }

        return false;
    }

    // Escaped HTML: the draft is text a model wrote from text customers wrote, so nothing in it is markup.
    public function html(string $draft): string
    {
        $parts = [];

        foreach ($this->sentences($draft) as $sentence) {
            $html = e($sentence['text']);

            foreach ($sentence['figures'] as $figure) {
                $html = str_replace(e($figure), '<mark class="figure">'.e($figure).'</mark>', $html);
            }

            $parts[] = $sentence['uncited']
                ? '<span class="uncited" title="No citation for this sentence">'.$html.'</span>'
                : $html;
        }

        return implode(' ', $parts);
    }

    private function statesSomething(string $sentence): bool
    {
        if (Str::endsWith($sentence, '?')) {
            return false;
        }

        if (preg_match(self::SOCIAL, $sentence) === 1) {
            return false;
        }

        return str_word_count($sentence) >= 5;
    }
}
