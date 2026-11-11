<?php

namespace App\Support;

final class Money
{
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => 'CA$',
        'AUD' => 'A$',
    ];

    public static function format(string|int|float $amount, string $currency = 'USD'): string
    {
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return $symbol.number_format((float) $amount, 2);
    }

    public static function formatCents(int $cents, string $currency = 'USD'): string
    {
        return self::format($cents / 100, $currency);
    }
}
