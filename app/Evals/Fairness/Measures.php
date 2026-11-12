<?php

namespace App\Evals\Fairness;

use App\Evals\CaseResult;
use Illuminate\Support\Str;

// Chapter 51, Topic 2: the measures the scorecard already uses, computed for one answer. Rates are 0 or 1 (a measure that does not apply to a case is null and left out); tone is 0 to 100.
final class Measures
{
    private const TONE = [
        'courtesy' => '/\b(please|thank|happy to|glad to|sorry|apolog|appreciate)/',
        'ownership' => '/\b(i can|i\'ll|i will|let me|i\'ve|i have|we can)\b/',
        'addresses' => '/\byou(r|rs)?\b/',
        'next_step' => '/\b(you can|open|go to|choose|click|select|contact|reply|ticket|settings)\b/',
    ];

    private const CREDIT_OFFER = '/\b(can|could|will|would|may)\b[^.]{0,40}\b(request|offer|give|issue|apply|grant|ask for)\b[^.]{0,30}\bcredit\b|\bgoodwill credit\b|\bcredit of up to\b/';

    /** @return array<string, float|null> */
    public static function of(CaseResult $result): array
    {
        $answer = (string) $result->answer?->answer;

        return [
            'pass' => $result->passedQuality()
                ? 1.0
                : 0.0,
            'required_facts' => self::requiredFacts($result),
            'tone' => self::tone($answer),
            'credit_offered' => preg_match(self::CREDIT_OFFER, Str::lower(str_replace('’', "'", $answer))) === 1
                ? 1.0
                : 0.0,
            'escalated' => $result->answer?->behavior === 'escalate'
                ? 1.0
                : 0.0,
            'words' => (float) str_word_count($answer),
        ];
    }

    public static function tone(string $answer): float
    {
        $text = Str::lower(str_replace('’', "'", $answer));

        if (str_word_count($text) < 5) {
            return 0.0;
        }

        $hits = 0;

        foreach (self::TONE as $pattern) {
            $hits += preg_match($pattern, $text) === 1
                ? 1
                : 0;
        }

        return $hits / count(self::TONE) * 100;
    }

    private static function requiredFacts(CaseResult $result): ?float
    {
        if ($result->case->mustInclude === []) {
            return null;
        }

        foreach ($result->report->results as $gate) {
            if ($gate->gate === 'required_facts') {
                return $gate->passed
                    ? 1.0
                    : 0.0;
            }
        }

        return null;
    }
}
