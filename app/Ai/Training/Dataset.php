<?php

namespace App\Ai\Training;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 40, Topic 8: a dataset version is a directory of files. The export writes train.jsonl (prompt-and-completion rows) and manifest.jsonl (one metadata line per row, never inside a row); review, cleaning and the split write the files that follow, and publish writes manifest.json. Rows and metadata travel by line number until the split gives each its own pair of files.
final class Dataset
{
    public function __construct(private string $directory) {}

    public static function named(string $name): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not a dataset name: lowercase letters, digits, dots, hyphens and underscores.");
        }

        return new self(rtrim((string) config('ai.training.datasets_path'), '/').'/'.$name);
    }

    public function name(): string
    {
        return basename($this->directory);
    }

    public function path(string $file = ''): string
    {
        return $file === ''
            ? $this->directory
            : $this->directory.'/'.$file;
    }

    public function exists(string $file = 'train.jsonl'): bool
    {
        return File::exists($this->path($file));
    }

    /** @return list<array> */
    public function read(string $file): array
    {
        if (! $this->exists($file)) {
            throw new InvalidArgumentException("Dataset {$this->name()} has no {$file}.");
        }

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            file($this->path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        )));
    }

    /** @param  list<array>  $rows */
    public function write(string $file, array $rows): void
    {
        File::ensureDirectoryExists(dirname($this->path($file)));

        File::put($this->path($file), implode('', array_map(fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", $rows)));
    }

    public function readJson(string $file): array
    {
        if (! $this->exists($file)) {
            return [];
        }

        return json_decode(File::get($this->path($file)), true) ?? [];
    }

    public function writeJson(string $file, array $data): void
    {
        File::ensureDirectoryExists($this->directory);

        File::put($this->path($file), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function hash(string $file): string
    {
        return 'sha256:'.hash_file('sha256', $this->path($file));
    }

    // The records a stage works on: the export (train.jsonl and manifest.jsonl, as written), the reviewed set, or the finished version (train and validation with their metadata files).
    /** @return list<array> */
    public function records(string $stage): array
    {
        return match ($stage) {
            'export' => $this->exists('export/train.jsonl')
                ? $this->joined('export/train.jsonl', 'export/manifest.jsonl')
                : $this->joined(),
            'reviewed' => $this->read('reviewed.jsonl'),
            'cleaned' => $this->read('cleaned.jsonl'),
            'final' => [...$this->joined('train.jsonl', 'train.meta.jsonl'), ...$this->joined('validation.jsonl', 'validation.meta.jsonl')],
            default => throw new InvalidArgumentException("Stage [{$stage}] is not export, reviewed, cleaned or final."),
        };
    }

    // The exported rows with their metadata beside them, one record per example: what review, cleaning and the scans read.
    /** @return list<array> */
    public function joined(string $rows = 'train.jsonl', string $meta = 'manifest.jsonl'): array
    {
        $rowList = $this->read($rows);

        $metaList = $this->read($meta);

        if (count($rowList) !== count($metaList)) {
            throw new InvalidArgumentException("{$rows} has ".count($rowList)." rows and {$meta} has ".count($metaList).' lines: they travel together.');
        }

        return array_map(fn (array $row, array $meta) => ['id' => $meta['id'], ...$row, 'meta' => $meta], $rowList, $metaList);
    }
}
