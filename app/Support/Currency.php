<?php

namespace App\Support;

final class Currency
{
    public const CODES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'MXN', 'BRL', 'INR', 'SGD', 'ZAR'];

    public static function codes(): array
    {
        return self::CODES;
    }
}
