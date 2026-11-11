<?php

namespace App\Ai\Encoding;

final class BenchPrompt
{
    public static function system(): string
    {
        return 'You answer questions about the invoice data you are given. Reply with only the answer: an invoice number, a number, a date or a status. No explanation.';
    }

    public static function messages(string $data, string $question): array
    {
        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => "{$data}\n\nQuestion: {$question}"],
        ];
    }

    public static function matches(string $answer, string $expected): bool
    {
        $answer = mb_strtolower(trim($answer));
        $expected = mb_strtolower(trim($expected));

        if (is_numeric($expected)) {
            return self::containsNumber($answer, (float) $expected);
        }

        return str_contains($answer, $expected);
    }

    private static function containsNumber(string $answer, float $expected): bool
    {
        $withoutNumbers = preg_replace('/inv-\d+/', ' ', $answer);

        $withoutNumbers = preg_replace('/\d{4}-\d{2}-\d{2}/', ' ', $withoutNumbers);

        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $withoutNumbers, $found);

        foreach ($found[0] as $candidate) {
            if (abs((float) str_replace(',', '', $candidate) - $expected) < 0.005) {
                return true;
            }
        }

        return false;
    }
}
