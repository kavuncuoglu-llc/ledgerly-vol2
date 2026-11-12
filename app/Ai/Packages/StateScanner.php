<?php

namespace App\Ai\Packages;

use Illuminate\Support\Facades\File;

// Chapter 48, Topic 7: what a package keeps. Migrations it ships (and whether it runs them or only offers them), the tables they create, and the settings in its published config that choose where something is kept: a history store, a cache, a disk. Each one is personal data until proven otherwise, and each must be in Chapter 34's data map.
final class StateScanner
{
    private const STORE_WORDS = ['history', 'memory', 'store', 'storage', 'persist', 'disk', 'session', 'cache'];

    /** @return array{tables: list<string>, migrations: list<string>, runs_migrations: bool, offers_migrations: bool, stores: list<string>} */
    public function scan(string $directory): array
    {
        $migrations = [];
        $tables = [];

        foreach ($this->files($directory, 'database', '*.php') as $file) {
            $migrations[] = $this->relative($directory, $file);

            preg_match_all('/Schema::create\(\s*[\'"]([\w.]+)[\'"]/', (string) file_get_contents($file), $found);

            array_push($tables, ...$found[1]);
        }

        $source = $this->source($directory);

        return [
            'tables' => array_values(array_unique($tables)),
            'migrations' => $migrations,
            'runs_migrations' => str_contains($source, 'loadMigrationsFrom'),
            'offers_migrations' => str_contains($source, 'publishesMigrations') || preg_match('/publishes\([^;]*migrations/s', $source) === 1,
            'stores' => $this->stores($directory),
        ];
    }

    /** @return list<string> "file:line key" for every config line whose key names a place data is kept */
    private function stores(string $directory): array
    {
        $found = [];

        foreach ($this->files($directory, 'config', '*.php') as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                if (preg_match('/^\s*[\'"]([\w.-]+)[\'"]\s*=>/', $line, $key) !== 1) {
                    continue;
                }

                foreach (self::STORE_WORDS as $word) {
                    if (str_contains(strtolower($key[1]), $word)) {
                        $found[] = $this->relative($directory, $file).':'.($number + 1).' '.$key[1];

                        break;
                    }
                }
            }
        }

        return $found;
    }

    private function source(string $directory): string
    {
        $text = '';

        foreach ($this->files($directory, 'src', '*.php') as $file) {
            $text .= file_get_contents($file)."\n";
        }

        return $text;
    }

    /** @return list<string> */
    private function files(string $directory, string $folder, string $pattern): array
    {
        $root = "{$directory}/{$folder}";

        if (! is_dir($root)) {
            return [];
        }

        return collect(File::allFiles($root))
            ->filter(fn ($file) => fnmatch($pattern, $file->getFilename()))
            ->map(fn ($file) => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    private function relative(string $directory, string $file): string
    {
        return ltrim(substr($file, strlen($directory)), '/');
    }
}
