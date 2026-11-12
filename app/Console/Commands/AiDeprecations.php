<?php

namespace App\Console\Commands;

use App\Ai\Deprecations\DeprecationAlerts;
use App\Ai\Deprecations\DeprecationEntry;
use App\Ai\Deprecations\DeprecationTable;
use App\Ai\Deprecations\InvalidDeprecationEntry;
use App\Ai\Deprecations\RoutedModels;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class AiDeprecations extends Command
{
    protected $signature = 'ai:deprecations
        {--notify : Raise the 90, 30 and 7 day alerts that have not been raised yet (the scheduler runs this daily)}
        {--strict : Fail when an identifier that has retired is still routed to (the CI check)}
        {--today= : Count days from this date (YYYY-MM-DD) instead of today}
        {--json : Print the table as JSON}';

    protected $description = 'Everything with a date, in one table: hosted models, their fine-tunes, API versions, abandoned packages and review dates for local models (Chapter 50, Topic 4)';

    public function handle(DeprecationTable $table, DeprecationAlerts $alerts, RoutedModels $routed): int
    {
        try {
            $entries = $table->entries($this->today());
        } catch (InvalidDeprecationEntry $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->option('json')
            ? $this->line((string) json_encode($entries->map(fn (DeprecationEntry $entry) => $this->row($entry, $table))->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            : $this->print($entries, $table);

        if ($this->option('notify')) {
            foreach ($alerts->raise($entries) as $text) {
                $this->warn($text);
            }
        }

        return $this->option('strict')
            ? $this->strict($entries, $routed)
            : self::SUCCESS;
    }

    private function strict($entries, RoutedModels $routed): int
    {
        $failures = 0;

        $routedNow = $routed->all();

        foreach ($entries->filter(fn (DeprecationEntry $entry) => $entry->isRoutable() && $entry->isRetired()) as $entry) {
            foreach ($routedNow[$entry->id] ?? [] as $provider) {
                $this->error("{$entry->kind} {$entry->id} retired on {$entry->date->toDateString()} and the provider [{$provider}] still routes to it.");

                $failures++;
            }
        }

        return $failures === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function print($entries, DeprecationTable $table): void
    {
        $this->table(
            ['kind', 'id', 'retires', 'days', 'replacement', 'read', 'step'],
            $entries->map(fn (DeprecationEntry $entry) => [
                $entry->kind,
                $entry->id.($entry->example ? ' (example)' : ''),
                $entry->state === DeprecationEntry::FOLLOWS ? ($entry->whenText().' (follows '.$entry->follows.')') : $entry->whenText(),
                $entry->daysLeft ?? '—',
                $entry->replacement ?: '—',
                $entry->read ?: '—',
                $this->step($entry, $table),
            ])->all(),
        );

        foreach ($table->notConfigured() as $kind) {
            $this->line("not configured here: a {$kind} entry with no id (set its variable to track it)");
        }
    }

    private function step(DeprecationEntry $entry, DeprecationTable $table): string
    {
        if ($entry->isRetired()) {
            return 'RETIRED: nothing may route to it';
        }

        $band = $entry->band($table->alertDays());

        return match (true) {
            $band === null => '—',
            $band <= 7 => 'page the owner unless promoted',
            $band <= 30 => 'replacement in shadow or canary',
            default => 'evaluate the replacement',
        };
    }

    private function row(DeprecationEntry $entry, DeprecationTable $table): array
    {
        return [
            'kind' => $entry->kind,
            'id' => $entry->id,
            'state' => $entry->state,
            'date' => $entry->date?->toDateString(),
            'days_left' => $entry->daysLeft,
            'replacement' => $entry->replacement,
            'read' => $entry->read,
            'source' => $entry->source,
            'band' => $entry->band($table->alertDays()),
            'example' => $entry->example,
        ];
    }

    private function today(): ?CarbonImmutable
    {
        $option = $this->option('today');

        if ($option === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $option);
        } catch (Throwable) {
            throw new InvalidDeprecationEntry("--today takes YYYY-MM-DD, not [{$option}].");
        }
    }
}
