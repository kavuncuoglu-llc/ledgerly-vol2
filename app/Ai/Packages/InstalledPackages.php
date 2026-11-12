<?php

namespace App\Ai\Packages;

use Carbon\CarbonImmutable;

// Chapter 48, Topic 7: the installed copies, read from vendor/composer/installed.json, which is the lock file as it was installed. Nothing leaves the machine.
final class InstalledPackages
{
    public function __construct(private ?string $file = null) {}

    public function find(string $name): ?PackageFacts
    {
        foreach ($this->all() as $package) {
            if (strtolower((string) $package['name']) === strtolower($name)) {
                return $this->facts($package);
            }
        }

        return null;
    }

    /** @return list<array> */
    public function all(): array
    {
        $file = $this->file ?? base_path('vendor/composer/installed.json');

        if (! is_file($file)) {
            return [];
        }

        $document = json_decode((string) file_get_contents($file), true);

        return $document['packages'] ?? [];
    }

    /** @return list<string> every installed package whose name contains one of the patterns */
    public function matching(array $patterns): array
    {
        $names = array_map(fn (array $package) => (string) $package['name'], $this->all());

        return array_values(array_filter($names, fn (string $name) => $this->isAi($name, $patterns)));
    }

    public function isAi(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($name), strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function facts(array $package): PackageFacts
    {
        $base = dirname($this->file ?? base_path('vendor/composer/installed.json'));

        $directory = isset($package['install-path'])
            ? realpath($base.'/'.$package['install-path'])
            : false;

        return new PackageFacts(
            name: (string) $package['name'],
            version: (string) $package['version'],
            installed: true,
            licenses: array_values((array) ($package['license'] ?? [])),
            require: (array) ($package['require'] ?? []),
            released: isset($package['time'])
                ? CarbonImmutable::parse($package['time'])
                : null,
            directory: $directory === false
                ? null
                : $directory,
            abandoned: $package['abandoned'] ?? null,
        );
    }
}
