<?php

namespace App\Ai\Privacy\Detectors;

use App\Ai\Privacy\Detector;
use App\Ai\Privacy\Span;

// ddd-dd-dddd, minus what the Social Security Administration never issues: area 000, 666 and 900 to 999, group 00, serial 0000.
final class UsSocialSecurityNumber implements Detector
{
    public function find(string $text): array
    {
        preg_match_all('/(?<![\d-])(\d{3})-(\d{2})-(\d{4})(?![\d-])/', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $spans = [];

        foreach ($m as $hit) {
            if (! self::issuable((int) $hit[1][0], (int) $hit[2][0], (int) $hit[3][0])) {
                continue;
            }

            $spans[] = new Span('SSN', $hit[0][1], strlen($hit[0][0]), $hit[0][0]);
        }

        return $spans;
    }

    private static function issuable(int $area, int $group, int $serial): bool
    {
        if ($area === 0 || $area === 666 || $area >= 900) {
            return false;
        }

        return $group !== 0 && $serial !== 0;
    }
}
