<?php

namespace App\Ai\Packages;

use Illuminate\Support\Facades\File;

// Chapter 48, Topic 7: where a package sends data by default. The hosts written as defaults in its published config, split into provider endpoints (called when that provider is used, which is the app's choice) and settings or dependencies that look like telemetry or hosted monitoring (called whether or not the app asked). Only the second kind is a finding.
final class OutboundScanner
{
    /**
     * @param  list<string>  $words  config('ai-packages.outbound_keys')
     * @param  array<string, string>  $require  the package's own dependencies
     * @return array{providers: list<string>, telemetry: list<string>}
     */
    public function scan(?string $directory, array $require, array $words): array
    {
        $providers = [];
        $telemetry = [];

        foreach ($require as $dependency => $constraint) {
            foreach ($words as $word) {
                if (str_contains(strtolower($dependency), strtolower($word))) {
                    $telemetry[] = "requires {$dependency}";

                    break;
                }
            }
        }

        foreach ($this->configFiles($directory) as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                $where = ltrim(substr($file, strlen((string) $directory)), '/').':'.($number + 1);

                if (preg_match('#https?://([A-Za-z0-9.-]+)#', $line, $host) === 1 && ! $this->isLocal($host[1])) {
                    $providers[] = "{$host[1]} ({$where})";
                }

                foreach ($words as $word) {
                    if (preg_match('/^\s*[\'"]([\w.-]*'.preg_quote($word, '/').'[\w.-]*)[\'"]\s*=>/i', $line, $key) === 1) {
                        $telemetry[] = "setting {$key[1]} ({$where})";

                        break;
                    }

                    if (preg_match('/env\(\s*[\'"]([A-Z0-9_]*'.preg_quote(strtoupper($word), '/').'[A-Z0-9_]*)[\'"]/', $line, $env) === 1) {
                        $telemetry[] = "env {$env[1]} ({$where})";

                        break;
                    }
                }
            }
        }

        return ['providers' => array_values(array_unique($providers)), 'telemetry' => array_values(array_unique($telemetry))];
    }

    /** @return list<string> */
    private function configFiles(?string $directory): array
    {
        if ($directory === null || ! is_dir("{$directory}/config")) {
            return [];
        }

        return collect(File::allFiles("{$directory}/config"))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    private function isLocal(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', 'example.com'], true) || str_ends_with($host, '.example.com');
    }
}
