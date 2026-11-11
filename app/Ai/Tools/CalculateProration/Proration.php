<?php

namespace App\Ai\Tools\CalculateProration;

// Chapter 38, Topic 8: the arithmetic a model should call, not learn. The rules are the help center's: a plan change charges the price difference for the share of the period that is left (billing/change-your-plan), and a late fee is 1.5% a month of the balance prorated by day, 0.05% a day, with nothing charged under 1.00 (billing/late-fees). Whole cents, rounded half up.
final class Proration
{
    public const LATE_FEE_MONTHLY_RATE = 0.015;

    public const LATE_FEE_DAYS_IN_MONTH = 30;

    public const LATE_FEE_MINIMUM_CENTS = 100;

    public static function planChange(float $oldPrice, float $newPrice, int $daysRemaining, int $daysInPeriod): array
    {
        $differenceCents = (int) round(($newPrice - $oldPrice) * 100);

        $chargeCents = (int) round($differenceCents * $daysRemaining / $daysInPeriod);

        return [
            'kind' => 'plan_change',
            'amount' => round($chargeCents / 100, 2),
            'formula' => sprintf('(%.2f - %.2f) x %d / %d', $newPrice, $oldPrice, $daysRemaining, $daysInPeriod),
        ];
    }

    public static function lateFee(float $balance, int $daysOverdue): array
    {
        $feeCents = (int) round($balance * 100 * self::LATE_FEE_MONTHLY_RATE / self::LATE_FEE_DAYS_IN_MONTH * $daysOverdue);

        $charged = $feeCents >= self::LATE_FEE_MINIMUM_CENTS;

        return [
            'kind' => 'late_fee',
            'amount' => $charged
                ? round($feeCents / 100, 2)
                : 0.0,
            'formula' => sprintf('%.2f x 1.5%% / 30 x %d', $balance, $daysOverdue),
            'below_minimum' => ! $charged && $feeCents > 0,
        ];
    }
}
