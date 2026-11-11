<?php

namespace App\Ai\Distillation;

use InvalidArgumentException;

// Chapter 46, Figure 46-8: `--categories=howto,plans,integrations`. A category here is an area of the help center (config ai.distillation.categories): which articles the teacher writes questions about, and which articles a real question's answer must have cited.
final class Categories
{
    /** @return list<string> */
    public static function validated(array $names): array
    {
        $known = array_keys((array) config('ai.distillation.categories'));

        foreach ($names as $name) {
            if (! in_array($name, $known, true)) {
                throw new InvalidArgumentException("No category [{$name}] in config ai.distillation.categories: ".implode(', ', $known).'.');
            }
        }

        return array_values($names);
    }

    public static function covers(string $category, string $articlePath): bool
    {
        foreach ((array) config("ai.distillation.categories.{$category}.articles") as $entry) {
            if ($articlePath === $entry || str_starts_with($articlePath, $entry.'/')) {
                return true;
            }
        }

        return false;
    }

    // `help:billing/late-fees#how-the-fee-is-calculated` and `help/billing/late-fees.md` are the same article.
    public static function pathOf(string $label): string
    {
        $path = preg_replace('/^help[:\/]/', '', $label);

        $path = explode('#', (string) $path)[0];

        return preg_replace('/\.md$/', '', $path);
    }
}
