<?php

namespace App\Ai\Privacy\Detectors;

use App\Ai\Privacy\Detector;
use App\Ai\Privacy\Span;

// A pattern plus a validator: thirteen to nineteen digits, with spaces or dashes, that pass the Luhn checksum.
final class CardNumber implements Detector
{
    public function find(string $text): array
    {
        preg_match_all('/\b(?:\d[ -]?){12,18}\d\b/', $text, $m, PREG_OFFSET_CAPTURE);

        return array_values(array_filter(array_map(
            fn ($hit) => new Span('CARD', $hit[1], strlen($hit[0]), $hit[0]),
            $m[0],
        ), fn (Span $s) => self::luhn(preg_replace('/\D/', '', $s->text))));
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;

        foreach (str_split(strrev($digits)) as $i => $d) {
            $d = (int) $d * ($i % 2 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return $sum % 10 === 0;
    }
}
