<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class Ledgerly
{
    public static function today(): CarbonImmutable
    {
        $asOf = config('ledgerly.as_of');

        if ($asOf) {
            return CarbonImmutable::parse($asOf)->startOfDay();
        }

        return CarbonImmutable::today();
    }
}
