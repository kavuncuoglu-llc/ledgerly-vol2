<?php

namespace App\Ai\Prompts;

use Illuminate\Support\Facades\File;
use ReflectionClass;

// Chapter 29, Topic 3: prompts.lock holds the reviewed hash of each prompt version's text. Change the text without bumping VERSION and the test fails; bump it and run php artisan prompts:lock after the review.
final class PromptLock
{
    public const SYSTEM_TEXTS = [
        SupportPrompt::class => 'base',
    ];

    public static function path(): string
    {
        return base_path('prompts.lock');
    }

    /** @return list<class-string> */
    public static function prompts(): array
    {
        return collect(glob(__DIR__.'/*.php'))
            ->map(fn (string $path) => 'App\\Ai\\Prompts\\'.basename($path, '.php'))
            ->filter(fn (string $class) => class_exists($class) && (new ReflectionClass($class))->hasConstant('VERSION'))
            ->sort()
            ->values()
            ->all();
    }

    public static function textOf(string $class): string
    {
        if ($class === RewritePrompt::class) {
            return collect(RewritePrompt::MODES)->map(fn (string $mode) => $class::system($mode))->implode("\n---\n");
        }

        $method = self::SYSTEM_TEXTS[$class] ?? 'system';

        return $class::$method();
    }

    public static function hashOf(string $class): string
    {
        return hash('sha256', self::textOf($class));
    }

    public static function reviewedHashFor(string $class, string $version): ?string
    {
        return self::read()[class_basename($class)][$version] ?? null;
    }

    public static function read(): array
    {
        if (! File::exists(self::path())) {
            return [];
        }

        return json_decode(File::get(self::path()), true) ?? [];
    }

    public static function write(array $lock): void
    {
        ksort($lock);

        File::put(self::path(), json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
