<?php

namespace App\Ai\Privacy\Detectors;

use App\Ai\Privacy\Detector;
use App\Ai\Privacy\Span;

// Country code, two check digits and a body, accepted only when the length is right for the country and the mod-97 check passes.
final class Iban implements Detector
{
    private const LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BR' => 29,
        'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29,
        'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20,
        'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22,
        'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25,
        'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'ST' => 25,
        'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    public function find(string $text): array
    {
        preg_match_all('/(?<![A-Za-z0-9])[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}/', $text, $m, PREG_OFFSET_CAPTURE);

        $spans = [];

        foreach ($m[0] as [$candidate, $offset]) {
            $span = $this->validated($candidate, $offset);

            if ($span !== null) {
                $spans[] = $span;
            }
        }

        return $spans;
    }

    private function validated(string $candidate, int $offset): ?Span
    {
        $expected = self::LENGTHS[substr($candidate, 0, 2)] ?? null;

        if ($expected === null) {
            return null;
        }

        $end = $this->endOfBody($candidate, $expected);

        if ($end === null) {
            return null;
        }

        $iban = substr($candidate, 0, $end);

        if (! self::mod97(str_replace(' ', '', $iban))) {
            return null;
        }

        return new Span('IBAN', $offset, $end, $iban);
    }

    // The candidate may run into the next word ("... 00 AND"): cut it where the country's length is reached.
    private function endOfBody(string $candidate, int $expected): ?int
    {
        $count = 0;

        for ($i = 0, $length = strlen($candidate); $i < $length; $i++) {
            if ($candidate[$i] === ' ') {
                continue;
            }

            $count++;

            if ($count === $expected) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function mod97(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $digits = '';

        foreach (str_split($rearranged) as $char) {
            $digits .= ctype_alpha($char)
                ? (string) (ord($char) - 55)
                : $char;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }
}
