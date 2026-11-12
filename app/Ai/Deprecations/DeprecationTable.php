<?php

namespace App\Ai\Deprecations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

// Chapter 50, Topic 4: everything with a date, in one place. An entry whose id is empty is not configured on this machine (a hosted model nobody set up) and is left out, not guessed at.
final class DeprecationTable
{
    /** @return Collection<int, DeprecationEntry> */
    public function entries(?CarbonImmutable $today = null): Collection
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        $rows = array_values((array) config('ai-deprecations.entries', []));

        $configured = array_values(array_filter($rows, fn (array $row) => filled($row['id'] ?? null)));

        $byId = collect($configured)->keyBy(fn (array $row) => (string) $row['id']);

        return collect($configured)->map(fn (array $row) => $this->resolve($row, $byId, $today))->values();
    }

    public function alertDays(): array
    {
        return array_map('intval', (array) config('ai-deprecations.alert_days', [90, 30, 7]));
    }

    public function notConfigured(): array
    {
        return array_values(array_map(
            fn (array $row) => (string) ($row['kind'] ?? '?'),
            array_filter((array) config('ai-deprecations.entries', []), fn (array $row) => blank($row['id'] ?? null)),
        ));
    }

    /** @return Collection<int, DeprecationEntry> */
    public function dated(?CarbonImmutable $today = null): Collection
    {
        return $this->entries($today)
            ->filter(fn (DeprecationEntry $entry) => $entry->isDated())
            ->sortBy('daysLeft')
            ->values();
    }

    private function resolve(array $row, Collection $byId, CarbonImmutable $today): DeprecationEntry
    {
        $kind = (string) ($row['kind'] ?? '');

        $id = (string) $row['id'];

        if (! in_array($kind, DeprecationEntry::KINDS, true)) {
            throw new InvalidDeprecationEntry("config/ai-deprecations.php: entry [{$id}] has kind [{$kind}]; use ".implode(', ', DeprecationEntry::KINDS).'.');
        }

        $common = [
            'replacement' => (string) ($row['replacement'] ?? ''),
            'read' => (string) ($row['read'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'example' => (bool) ($row['example'] ?? false),
        ];

        if (filled($row['follows'] ?? null)) {
            return $this->following($kind, $id, (string) $row['follows'], $byId, $today, $common);
        }

        if (filled($row['review'] ?? null)) {
            $date = $this->date($row['review'], $id);

            return new DeprecationEntry($kind, $id, DeprecationEntry::REVIEW, $date, $this->days($today, $date), ...$common);
        }

        $retires = (string) ($row['retires'] ?? '');

        if ($retires === DeprecationEntry::ABANDONED) {
            return new DeprecationEntry($kind, $id, DeprecationEntry::ABANDONED, null, null, ...$common);
        }

        $date = $this->date($retires, $id);

        return new DeprecationEntry($kind, $id, DeprecationEntry::RETIRES, $date, $this->days($today, $date), ...$common);
    }

    private function following(string $kind, string $id, string $base, Collection $byId, CarbonImmutable $today, array $common): DeprecationEntry
    {
        if (! $byId->has($base)) {
            throw new InvalidDeprecationEntry("config/ai-deprecations.php: [{$id}] follows [{$base}], which is not an entry (or is not configured).");
        }

        $baseRow = $byId[$base];

        if (filled($baseRow['follows'] ?? null)) {
            throw new InvalidDeprecationEntry("config/ai-deprecations.php: [{$id}] follows [{$base}], which follows another entry; point it at the base.");
        }

        $date = filled($baseRow['retires'] ?? null) && $baseRow['retires'] !== DeprecationEntry::ABANDONED
            ? $this->date($baseRow['retires'], $base)
            : null;

        $common['replacement'] = $common['replacement'] !== ''
            ? $common['replacement']
            : (string) ($baseRow['replacement'] ?? '');

        return new DeprecationEntry($kind, $id, DeprecationEntry::FOLLOWS, $date, $date === null ? null : $this->days($today, $date), ...[...$common, 'follows' => $base]);
    }

    private function date(mixed $value, string $id): CarbonImmutable
    {
        $text = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new InvalidDeprecationEntry("config/ai-deprecations.php: [{$id}] has the date [{$text}]; write YYYY-MM-DD.");
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $text);
        } catch (Throwable) {
            throw new InvalidDeprecationEntry("config/ai-deprecations.php: [{$id}] has the date [{$text}], which is not a date.");
        }

        if ($date->format('Y-m-d') !== $text) {
            throw new InvalidDeprecationEntry("config/ai-deprecations.php: [{$id}] has the date [{$text}], which is not a date.");
        }

        return $date;
    }

    private function days(CarbonImmutable $today, CarbonImmutable $date): int
    {
        return (int) $today->diffInDays($date, false);
    }
}
