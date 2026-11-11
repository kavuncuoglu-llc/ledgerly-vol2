<?php

namespace App\Ai\Registry;

use InvalidArgumentException;

// Chapter 44, Topic 5: config/ai.php names a model by registry alias (billing@production) and the alias is resolved when config is loaded, so a release is a registry change and a config refresh, not a code deploy.
// The aliases are a small JSON file the registry rewrites on every stage change. Config is read before the database is reachable (and `config:cache` freezes it), so this class touches only that file and the filesystem, never a model.
final class Aliases
{
    public const SLOTS = ['production', 'previous', 'canary', 'shadow'];

    private static ?string $file = null;

    public static function path(): string
    {
        return self::$file ?? (getenv('REGISTRY_ALIASES_FILE') ?: storage_path('app/registry/aliases.json'));
    }

    // A test (or a deployment that keeps the file elsewhere) points the registry at another file.
    public static function useFile(?string $path): void
    {
        self::$file = $path;
    }

    public static function resolve(string $alias, ?string $default = null): ?string
    {
        $entry = self::read()[$alias] ?? null;

        return is_array($entry) && is_string($entry['model'] ?? null)
            ? $entry['model']
            : $default;
    }

    /** @return array<string, array{version: int, model: string}> */
    public static function read(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, fn ($entry, $alias) => is_string($alias) && self::validAlias($alias) && is_array($entry) && self::validModel((string) ($entry['model'] ?? '')), ARRAY_FILTER_USE_BOTH);
    }

    /** @param  array<string, array{version: int, model: string}>  $aliases */
    public static function write(array $aliases): void
    {
        foreach ($aliases as $alias => $entry) {
            if (! self::validAlias((string) $alias)) {
                throw new InvalidArgumentException("[{$alias}] is not a registry alias: a name, @, then one of ".implode(', ', self::SLOTS).'.');
            }

            if (! self::validModel((string) ($entry['model'] ?? ''))) {
                throw new InvalidArgumentException("[{$entry['model']}] is not a model name a server could have: letters, digits and . _ : / - only.");
            }
        }

        ksort($aliases);

        $path = self::path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $temporary = $path.'.'.bin2hex(random_bytes(4));

        file_put_contents($temporary, json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        rename($temporary, $path);
    }

    // The role again, once per version that is in a trial: `local-billing-ft@canary` serves billing@canary, `local-billing-ft@shadow` serves billing@shadow. A role that is not in a trial has no such provider, so no route can name it.
    /** @return array<string, array> */
    public static function variants(string $role, string $name, array $base): array
    {
        $variants = [];

        foreach (['canary', 'shadow'] as $slot) {
            $entry = self::read()["{$name}@{$slot}"] ?? null;

            if ($entry === null) {
                continue;
            }

            $variants["{$role}@{$slot}"] = ['model' => $entry['model']] + $base;
        }

        return $variants;
    }

    public static function validAlias(string $alias): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*@('.implode('|', self::SLOTS).')$/', $alias);
    }

    public static function validModel(string $model): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $model);
    }
}
