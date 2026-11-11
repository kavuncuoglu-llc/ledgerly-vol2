<?php

namespace App\Ai\Compare;

use InvalidArgumentException;

final class GoldenSet
{
    /** @return list<GoldenCase> */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No golden set at {$path}.");
        }

        $cases = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $number => $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Line '.($number + 1)." of {$path} is not JSON.");
            }

            $cases[] = GoldenCase::fromArray($decoded);
        }

        return $cases;
    }

    /**
     * @param  list<GoldenCase>  $cases
     * @return list<GoldenCase>
     */
    public static function only(array $cases, ?string $feature, ?int $limit): array
    {
        if ($feature !== null && $feature !== '') {
            $cases = array_values(array_filter($cases, fn (GoldenCase $case) => $case->feature === $feature));
        }

        return $limit === null
            ? $cases
            : array_slice($cases, 0, $limit);
    }
}
