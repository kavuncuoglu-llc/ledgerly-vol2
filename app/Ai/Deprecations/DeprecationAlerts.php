<?php

namespace App\Ai\Deprecations;

use App\Ai\Budgets\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// Chapter 50, Topic 4: alerts at 90, 30 and 7 days, each tied to a step on the release ladder, and one alert per entry per step however often the command runs.
final class DeprecationAlerts
{
    public const STEPS = [
        90 => 'Open an evaluation task for the replacement: the golden set and the tripwire (Chapter 30).',
        30 => 'The replacement should be in a shadow run or a canary (Chapter 44).',
        7 => 'Page the owner if the replacement is not promoted. The old model stays as the previous version (Chapter 44).',
    ];

    public function __construct(private Notifier $notifier, private DeprecationTable $table) {}

    /** @param Collection<int, DeprecationEntry> $entries @return list<string> the alerts raised this time */
    public function raise(Collection $entries): array
    {
        $raised = [];

        foreach ($entries->filter(fn (DeprecationEntry $entry) => $entry->isDated()) as $entry) {
            $band = $entry->band($this->table->alertDays());

            if ($band === null) {
                continue;
            }

            $key = 'ai-deprecations.band.'.sha1($entry->kind.'|'.$entry->id);

            $last = Cache::get($key);

            if ($last !== null && $last <= $band) {
                continue;
            }

            Cache::forever($key, $band);

            $raised[] = $this->send($entry, $band);
        }

        return $raised;
    }

    private function send(DeprecationEntry $entry, int $band): string
    {
        $text = $this->text($entry, $band);

        if ($band <= 7) {
            $this->notifier->oncall("{$entry->kind} {$entry->id} retires in {$entry->daysLeft} days", $text);

            return $text;
        }

        $this->notifier->channel($text);

        return $text;
    }

    private function text(DeprecationEntry $entry, int $band): string
    {
        $when = $entry->daysLeft <= 0
            ? 'has retired'
            : "retires on {$entry->date->toDateString()} ({$entry->daysLeft} days)";

        $replacement = $entry->replacement === ''
            ? 'no replacement named yet'
            : "replacement: {$entry->replacement}";

        return "{$entry->kind} {$entry->id} {$when}; {$replacement}. ".self::STEPS[$band];
    }
}
