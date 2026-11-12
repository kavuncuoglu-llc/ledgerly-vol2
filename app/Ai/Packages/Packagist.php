<?php

namespace App\Ai\Packages;

use Carbon\CarbonImmutable;
use Composer\Semver\Semver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

// Chapter 48, Topic 7: Packagist's public, keyless metadata, advisories and release archives. The audit reads and unpacks; it never installs and never runs a package's code (a downloaded archive is only read as text).
final class Packagist
{
    /** @return list<array> every version, with the minified diffs expanded */
    public function versions(string $name): array
    {
        $this->validName($name);

        $response = Http::timeout((int) config('ai-packages.packagist.timeout'))
            ->acceptJson()
            ->get(rtrim((string) config('ai-packages.packagist.metadata'), '/')."/{$name}.json");

        if ($response->status() === 404) {
            throw new RuntimeException("Packagist has no package named {$name}.");
        }

        $response->throw();

        return $this->expanded($response->json("packages.{$name}") ?? []);
    }

    /** @param list<array> $versions */
    public function stable(array $versions): array
    {
        $stable = array_values(array_filter($versions, fn (array $version) => $this->isStable((string) ($version['version_normalized'] ?? $version['version'] ?? ''))));

        usort($stable, fn (array $a, array $b) => strcmp((string) $b['time'], (string) $a['time']));

        return $stable;
    }

    /** @return list<array> the advisories Packagist has on record for the package, all versions */
    public function advisories(string $name): array
    {
        $this->validName($name);

        $response = Http::timeout((int) config('ai-packages.packagist.timeout'))
            ->acceptJson()
            ->get((string) config('ai-packages.packagist.advisories'), ['packages' => [$name]]);

        $response->throw();

        return $response->json("advisories.{$name}") ?? [];
    }

    public function affects(array $advisory, string $version): bool
    {
        try {
            return Semver::satisfies(ltrim($version, 'v'), (string) ($advisory['affectedVersions'] ?? ''));
        } catch (Throwable) {
            return false;
        }
    }

    // The release archive of one version, unpacked under storage/app/package-audits/.cache for reading. Entries are written only if they stay inside the target directory, and only the file types an audit reads.
    public function archive(string $name, array $version): ?string
    {
        $url = $version['dist']['url'] ?? null;

        if (! is_string($url)) {
            return null;
        }

        $target = storage_path('app/package-audits/.cache/'.str_replace('/', '--', $name).'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $version['version']));

        if (is_dir($target)) {
            return $target;
        }

        $response = Http::timeout(60)->withOptions(['stream' => false])->get($url);

        $response->throw();

        $body = $response->body();

        if (strlen($body) > (int) config('ai-packages.packagist.max_archive_bytes')) {
            throw new RuntimeException("The archive of {$name} is larger than the audit reads.");
        }

        $zip = storage_path('app/package-audits/.cache/download-'.bin2hex(random_bytes(4)).'.zip');

        File::ensureDirectoryExists(dirname($zip));

        File::put($zip, $body);

        try {
            $this->unpack($zip, $target);
        } finally {
            File::delete($zip);
        }

        return $target;
    }

    private function unpack(string $zip, string $target): void
    {
        $archive = new ZipArchive;

        if ($archive->open($zip) !== true) {
            throw new RuntimeException('The release archive could not be opened.');
        }

        File::ensureDirectoryExists($target);

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entry = (string) $archive->getNameIndex($i);

            $relative = implode('/', array_slice(explode('/', $entry), 1));

            if ($relative === '' || str_ends_with($relative, '/')) {
                continue;
            }

            if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
                continue;
            }

            if (preg_match('/\.(php|json|md|txt|yml|yaml|stub)$/i', $relative) !== 1) {
                continue;
            }

            File::ensureDirectoryExists(dirname("{$target}/{$relative}"));

            File::put("{$target}/{$relative}", (string) $archive->getFromIndex($i));
        }

        $archive->close();
    }

    /** @param list<array> $versions */
    private function expanded(array $versions): array
    {
        $expanded = [];
        $current = null;

        foreach ($versions as $version) {
            if ($current === null) {
                $current = $version;
            } else {
                foreach ($version as $key => $value) {
                    if ($value === '__unset') {
                        unset($current[$key]);

                        continue;
                    }

                    $current[$key] = $value;
                }
            }

            $expanded[] = $current;
        }

        return $expanded;
    }

    private function isStable(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        return preg_match('/(dev|alpha|beta|rc|patch)/i', $normalized) !== 1 && ! str_starts_with($normalized, 'dev-');
    }

    private function validName(string $name): void
    {
        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name) !== 1) {
            throw new RuntimeException("[{$name}] is not a Composer package name (vendor/package, lowercase).");
        }
    }

    public function reachable(): bool
    {
        try {
            Http::timeout(5)->get(rtrim((string) config('ai-packages.packagist.metadata'), '/').'/composer/semver.json')->throw();

            return true;
        } catch (ConnectionException|RequestException) {
            return false;
        }
    }

    public function releasedAt(array $version): CarbonImmutable
    {
        return CarbonImmutable::parse($version['time']);
    }
}
