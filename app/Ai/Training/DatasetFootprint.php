<?php

namespace App\Ai\Training;

use Illuminate\Support\Facades\File;

// Chapter 34, Topic 8 and Chapter 40, Topic 3: user:forget cannot remove an example from a dataset version (a version is never rewritten), so it finds which versions hold a person's conversations, by the keyed hash each example records, and says which dataset:forget to run before the next training run.
final class DatasetFootprint
{
    /**
     * @param  list<int>  $conversationIds
     * @return array<string, int> dataset name => examples
     */
    public function of(array $conversationIds): array
    {
        $key = (string) config('ai.training.hash_key');

        $root = (string) config('ai.training.datasets_path');

        if ($key === '' || $conversationIds === [] || ! File::isDirectory($root)) {
            return [];
        }

        $hashes = array_flip(array_map(fn (int $id) => hash_hmac('sha256', (string) $id, $key), $conversationIds));

        $found = [];

        foreach (File::directories($root) as $directory) {
            $count = $this->examplesIn($directory, $hashes);

            if ($count > 0) {
                $found[basename($directory)] = $count;
            }
        }

        return $found;
    }

    private function examplesIn(string $directory, array $hashes): int
    {
        $count = 0;

        $files = File::exists("{$directory}/train.meta.jsonl")
            ? ['train.meta.jsonl', 'validation.meta.jsonl']
            : ['manifest.jsonl'];

        foreach ($files as $file) {
            if (! File::exists("{$directory}/{$file}")) {
                continue;
            }

            foreach (file("{$directory}/{$file}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $conversation = json_decode($line, true)['conversation'] ?? null;

                if ($conversation !== null && isset($hashes[$conversation])) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
