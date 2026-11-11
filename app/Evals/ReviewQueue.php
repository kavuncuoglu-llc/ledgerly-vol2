<?php

namespace App\Evals;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

// Chapter 29, Topic 8: candidates wait here, in storage/ (never committed), until a person labels or dismisses each one. One JSON line per candidate.
final class ReviewQueue
{
    public function __construct(private string $path) {}

    public static function default(): self
    {
        return new self(storage_path('eval/review-queue.jsonl'));
    }

    /** @return Collection<int, array> */
    public function all(): Collection
    {
        if (! File::exists($this->path)) {
            return collect();
        }

        return collect(file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter()
            ->values();
    }

    public function open(): Collection
    {
        return $this->all()->where('status', 'open')->values();
    }

    public function labeled(): Collection
    {
        return $this->all()->where('status', 'labeled')->values();
    }

    public function add(array $candidates): int
    {
        $known = $this->all()->pluck('id')->all();

        $new = collect($candidates)->reject(fn (array $candidate) => in_array($candidate['id'], $known, true))->values();

        $this->save($this->all()->concat($new)->all());

        return $new->count();
    }

    public function update(string $id, array $changes): void
    {
        $this->save($this->all()->map(fn (array $row) => $row['id'] === $id ? array_merge($row, $changes) : $row)->all());
    }

    private function save(array $rows): void
    {
        File::ensureDirectoryExists(dirname($this->path));

        File::put($this->path, collect($rows)->map(fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n")."\n");
    }
}
