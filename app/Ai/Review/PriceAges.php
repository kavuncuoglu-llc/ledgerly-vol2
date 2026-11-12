<?php

namespace App\Ai\Review;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

// Chapter 50, Topic 9: a price checked more than 90 days ago is a price that may no longer be true. Every model entry carries the day it was read (its own `checked`, or the price map's `checked_on`).
final class PriceAges
{
    public const MAX_DAYS = 90;

    /** @return Collection<int, array{model: string, checked: ?string, days: ?int, source: string, illustrative: bool, stale: bool}> */
    public function all(CarbonImmutable $today): Collection
    {
        $default = config('ai-pricing.checked_on');

        $rows = [];

        foreach ((array) config('ai-pricing.models') as $model => $entry) {
            $checked = $entry['checked'] ?? $default;

            $days = $this->daysSince($checked, $today);

            $illustrative = (bool) ($entry['illustrative'] ?? false);

            $rows[] = [
                'model' => (string) $model,
                'checked' => $checked === null || $checked === '' ? null : (string) $checked,
                'days' => $days,
                'source' => (string) ($entry['source'] ?? ''),
                'illustrative' => $illustrative,
                'stale' => ! $illustrative && ($days === null || $days > self::MAX_DAYS),
            ];
        }

        return collect($rows);
    }

    private function daysSince(mixed $checked, CarbonImmutable $today): ?int
    {
        if ($checked === null || $checked === '') {
            return null;
        }

        try {
            return (int) CarbonImmutable::createFromFormat('!Y-m-d', (string) $checked)->diffInDays($today, false);
        } catch (Throwable) {
            return null;
        }
    }
}
