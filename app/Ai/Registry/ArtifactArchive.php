<?php

namespace App\Ai\Registry;

use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\File;

// Chapter 44, Topic 6: a retired version's artifacts are archived, so it can be served again (slowly) from them. A copy under config('ai.registry.archive'), named for the version, each file checked against its recorded hash after the copy.
class ArtifactArchive
{
    // Where a file of the entry is now: its own path, or its archived copy once the original is gone.
    public function locate(ModelRegistryEntry $entry, string $path): string
    {
        return rtrim((string) config('ai.registry.archive'), '/')."/{$entry->name}/v{$entry->version}/".basename($path);
    }

    public function archive(ModelRegistryEntry $entry): bool
    {
        $files = (array) ($entry->artifacts['files'] ?? []);

        if ($files === []) {
            return false;
        }

        $directory = rtrim((string) config('ai.registry.archive'), '/')."/{$entry->name}/v{$entry->version}";

        File::ensureDirectoryExists($directory);

        foreach ($files as $key => $path) {
            if (! is_file($path)) {
                return false;
            }

            $target = $directory.'/'.basename($path);

            File::copy($path, $target);

            if ('sha256:'.hash_file('sha256', $target) !== ($entry->artifacts[$key] ?? null)) {
                File::delete($target);

                return false;
            }
        }

        return true;
    }
}
