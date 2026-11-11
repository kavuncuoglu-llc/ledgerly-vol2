<?php

namespace App\Ai\Retrieval;

use Illuminate\Support\Str;

final class CitationChecker
{
    private const NUMBER_WORDS = [
        'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7',
        'eight' => '8', 'nine' => '9', 'ten' => '10', 'eleven' => '11', 'twelve' => '12', 'fifteen' => '15',
        'twenty' => '20', 'thirty' => '30', 'forty' => '40', 'fifty' => '50', 'sixty' => '60', 'ninety' => '90',
        'hundred' => '100',
    ];

    private const STOP_WORDS = [
        'about', 'after', 'again', 'also', 'because', 'before', 'being', 'could', 'every', 'from', 'have', 'into',
        'might', 'other', 'should', 'since', 'still', 'their', 'there', 'these', 'they', 'this', 'those', 'through',
        'under', 'until', 'where', 'which', 'while', 'with', 'would', 'your', 'ledgerly',
    ];

    public function check(string $answer, array $labelToChunk, bool $requireCitations = true): CitationReport
    {
        $errors = [];

        preg_match_all('/\[([^\]]+)\]/u', $answer, $matches);

        $cited = array_unique($matches[1]);

        foreach ($cited as $label) {
            if (! isset($labelToChunk[$label])) {
                $errors[] = "Citation [{$label}] does not match any provided passage.";
            }
        }

        if ($requireCitations) {
            foreach ($this->sentences($answer) as $sentence) {
                $errors = [...$errors, ...$this->sentenceErrors($sentence, $labelToChunk)];
            }
        }

        return new CitationReport(
            errors: $errors,
            cited: array_intersect_key($labelToChunk, array_flip($cited)),
        );
    }

    private function sentenceErrors(string $sentence, array $labelToChunk): array
    {
        $errors = [];

        $labels = $this->labelsIn($sentence);

        if ($this->isFactual($sentence) && $labels === []) {
            $errors[] = 'Uncited factual sentence: “'.Str::limit($sentence, 80).'”';
        }

        foreach ($labels as $label) {
            if (isset($labelToChunk[$label]) && ! $this->overlaps($sentence, $labelToChunk[$label]->body())) {
                $errors[] = "Sentence cites [{$label}] but shares no key terms or numbers with it.";
            }
        }

        return $errors;
    }

    private function sentences(string $answer): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z\[“"\d])/u', trim($answer)) ?: [];

        $sentences = [];

        foreach ($parts as $part) {
            $onlyCitations = trim(preg_replace('/\[[^\]]+\]\.?/u', '', $part)) === '';

            if ($onlyCitations && $sentences !== []) {
                $sentences[array_key_last($sentences)] .= ' '.$part;               // a citation that landed after the full stop belongs to the sentence before it

                continue;
            }

            $sentences[] = $part;
        }

        return $sentences;
    }

    private function labelsIn(string $sentence): array
    {
        preg_match_all('/\[([^\]]+)\]/u', $sentence, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function isFactual(string $sentence): bool
    {
        $text = preg_replace('/\[[^\]]+\]/u', '', $sentence);

        return (bool) preg_match('/\d|\b(fee|fees|policy|days?|percent|due|charged|applied|refund|refunds)\b/i', $text);
    }

    private function overlaps(string $sentence, string $chunk): bool
    {
        $sentence = preg_replace('/\[[^\]]+\]/u', '', $sentence);

        $numbers = $this->numbers($sentence);

        if ($numbers !== []) {
            return array_diff($numbers, $this->numbers($chunk)) === [];            // every number the answer states must be in the passage
        }

        return count(array_intersect($this->terms($sentence), $this->terms($chunk))) >= 2;
    }

    private function numbers(string $text): array
    {
        $text = preg_replace_callback(
            '/\b('.implode('|', array_keys(self::NUMBER_WORDS)).')\b/i',
            fn (array $match) => self::NUMBER_WORDS[strtolower($match[1])],
            $text,
        );

        preg_match_all('/\d+(?:[.,]\d+)*/', $text, $matches);

        return array_values(array_unique(array_map(fn (string $number) => rtrim(str_replace(',', '', $number), '.'), $matches[0])));
    }

    private function terms(string $text): array
    {
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $matches);

        $terms = array_map(fn (string $word) => rtrim($word, 's'), $matches[0]);

        return array_values(array_diff(array_unique($terms), self::STOP_WORDS));
    }
}
