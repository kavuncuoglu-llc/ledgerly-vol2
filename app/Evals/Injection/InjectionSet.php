<?php

namespace App\Evals\Injection;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class InjectionSet
{
    /** @return Collection<int, InjectionFixture> */
    public static function load(string $directory): Collection
    {
        $path = str_starts_with($directory, '/')
            ? $directory
            : base_path($directory);

        if (! File::isDirectory($path)) {
            throw new InvalidArgumentException("No fixture directory at {$directory}.");
        }

        $fixtures = collect(File::glob($path.'/*.jsonl'))
            ->sort()
            ->flatMap(fn (string $file) => self::linesOf($file))
            ->values();

        $duplicate = $fixtures->pluck('id')->duplicates()->first();

        if ($duplicate !== null) {
            throw new InvalidArgumentException("Fixture id '{$duplicate}' is used twice.");
        }

        return $fixtures;
    }

    // The smoke subset has to stay the same between commits: the first n fixtures of every carrier, in id order.
    public static function smoke(Collection $fixtures, int $perCarrier): Collection
    {
        return $fixtures
            ->groupBy('carrier')
            ->sortKeys()
            ->flatMap(fn (Collection $group) => $group->sortBy('id')->take($perCarrier))
            ->values();
    }

    private static function linesOf(string $file): array
    {
        $fixtures = [];

        foreach (preg_split('/\R/', trim(File::get($file))) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (! is_array($row)) {
                throw new InvalidArgumentException(basename($file).' line '.($number + 1).' is not a JSON object.');
            }

            $fixtures[] = InjectionFixture::fromArray($row);
        }

        return $fixtures;
    }
}
