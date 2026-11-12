<?php

namespace App\Evals\Fairness;

use Illuminate\Support\Facades\File;

// Chapter 51, Topic 3: where the latest result is kept, for the scorecard and the feature card to read. One file, overwritten each run, never committed.
final class FairnessReport
{
    public static function path(): string
    {
        return storage_path('eval/fairness-latest.json');
    }

    public static function save(array $result): void
    {
        File::ensureDirectoryExists(dirname(self::path()));

        File::put(self::path(), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function latest(): ?array
    {
        if (! File::exists(self::path())) {
            return null;
        }

        $result = json_decode(File::get(self::path()), true);

        return is_array($result)
            ? $result
            : null;
    }
}
