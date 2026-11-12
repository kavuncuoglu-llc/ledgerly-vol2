<?php

namespace App\Ai\Packages;

use Illuminate\Support\Facades\File;

// Chapter 48, Topic 7: the signals of each audit, kept so the next quarter's review (Chapter 50) can say what moved: one file per package, one entry per day, the latest entry replacing a second run on the same day.
final class AuditHistory
{
    public function previous(string $package, string $before): ?array
    {
        $entries = array_filter($this->entries($package), fn (array $entry) => $entry['date'] < $before);

        return $entries === []
            ? null
            : end($entries);
    }

    public function record(string $package, array $entry): void
    {
        $entries = array_values(array_filter($this->entries($package), fn (array $existing) => $existing['date'] !== $entry['date']));

        $entries[] = $entry;

        File::ensureDirectoryExists(dirname($this->path($package)));

        File::put($this->path($package), json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @return list<array> */
    public function entries(string $package): array
    {
        if (! is_file($this->path($package))) {
            return [];
        }

        return json_decode((string) file_get_contents($this->path($package)), true) ?? [];
    }

    private function path(string $package): string
    {
        return storage_path('app/package-audits/'.str_replace('/', '--', $package).'.json');
    }
}
