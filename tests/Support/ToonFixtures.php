<?php

namespace Tests\Support;

use RuntimeException;

// Chapter 48, Topic 4: the TOON spec's own conformance fixtures (toon-format/spec, MIT), one dataset row per fixture. Rows are keyed on file path and index, never on the fixture's name, which the spec rewrites whenever its wording changes. An encode fixture keeps JSON objects as objects, so an object that should have been a list cannot pass unnoticed; a decode fixture's expected value is compared as PHP arrays, the package's documented contract.
final class ToonFixtures
{
    /** @return list<string> the spec versions that have a fixtures directory */
    public static function versions(string $base): array
    {
        return array_map('basename', glob("{$base}/*", GLOB_ONLYDIR) ?: []);
    }

    /** @return array<string, array{0: string, 1: string, 2: mixed, 3: mixed, 4: array, 5: bool}> key => [version, category, input, expected, options, shouldError] */
    public static function all(string $base): array
    {
        $rows = [];

        foreach (self::versions($base) as $version) {
            foreach (self::load("{$base}/{$version}") as $key => [$category, $input, $expected, $options, $shouldError]) {
                $rows["{$version} {$key}"] = [$version, $category, $input, $expected, $options, $shouldError];
            }
        }

        return $rows;
    }

    /** @return array<string, array{0: string, 1: mixed, 2: mixed, 3: array, 4: bool}> key => [category, input, expected, options, shouldError] */
    public static function load(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("No TOON fixtures at {$directory}: a spec version with no fixtures has no conformance claim.");
        }

        $rows = [];

        foreach (['encode', 'decode'] as $category) {
            foreach (glob("{$directory}/{$category}/*.json") ?: [] as $file) {
                $document = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);

                foreach ($document->tests as $index => $test) {
                    $rows["{$category}/".basename($file).'#'.$index] = [
                        $category,
                        $test->input,
                        $test->expected ?? null,
                        (array) ($test->options ?? new \stdClass),
                        (bool) ($test->shouldError ?? false),
                    ];
                }
            }
        }

        if ($rows === []) {
            throw new RuntimeException("{$directory} has no fixtures.");
        }

        return $rows;
    }
}
