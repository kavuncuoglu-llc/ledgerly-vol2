<?php

namespace App\Evals;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 29, Topic 4: the golden set is JSON lines in the repository, changed by reviewed pull requests. One file per category; synthetic/ and production/ hold the other two sources.
final class GoldenSet
{
    public function __construct(private string $directory) {}

    public static function default(): self
    {
        return new self(base_path((string) config('ai.eval.golden_path', 'tests/golden')));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return Collection<int, GoldenCase> */
    public function all(?string $source = null): Collection
    {
        $cases = $this->files()
            ->flatMap(fn (string $path) => $this->read($path))
            ->sortBy('id')
            ->values();

        $this->assertUniqueIds($cases);

        if ($source === null) {
            return $cases;
        }

        return $cases
            ->filter(fn (GoldenCase $case) => $case->source === $source)
            ->values();
    }

    /** @return Collection<int, GoldenCase> */
    public function subset(string $name): Collection
    {
        return match ($name) {
            'all' => $this->all(),
            'smoke' => $this->smoke((int) config('ai.eval.smoke', 25)),
            'human', 'synthetic', 'production' => $this->all($name),
            default => throw new InvalidArgumentException("Unknown subset '{$name}'. Use all, smoke, human, synthetic or production."),
        };
    }

    // The smoke subset has to stay the same between commits or its pass rate means nothing: sort by a hash of the id, deal round-robin across behaviors, and always keep one case of every kind of never-gate.
    public function smoke(int $size): Collection
    {
        $groups = $this->all()
            ->groupBy(fn (GoldenCase $case) => $case->expectedBehavior.'/'.$case->source)
            ->map(fn (Collection $group) => $group->sortBy(fn (GoldenCase $case) => crc32($case->id))->values())
            ->sortKeys()
            ->values();

        $picked = collect();

        for ($round = 0; $picked->count() < $size; $round++) {
            $added = false;

            foreach ($groups as $group) {
                if ($picked->count() >= $size) {
                    break;
                }

                if (isset($group[$round])) {
                    $picked->push($group[$round]);

                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        return $picked
            ->sortBy('id')
            ->values();
    }

    public function find(string $id): ?GoldenCase
    {
        return $this->all()->firstWhere('id', $id);
    }

    public function write(string $path, iterable $cases): int
    {
        File::ensureDirectoryExists(dirname($path));

        $lines = [];

        foreach ($cases as $case) {
            $lines[] = $case->toJson();
        }

        File::put($path, $lines === [] ? '' : implode("\n", $lines)."\n");

        return count($lines);
    }

    /** @return Collection<int, string> */
    private function files(): Collection
    {
        if (! File::isDirectory($this->directory)) {
            return collect();
        }

        return collect(File::allFiles($this->directory))
            ->filter(fn ($file) => $file->getExtension() === 'jsonl')
            ->map(fn ($file) => $file->getPathname())
            ->sort()
            ->values();
    }

    /** @return Collection<int, GoldenCase> */
    private function read(string $path): Collection
    {
        $cases = collect();

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $number => $line) {
            $row = json_decode($line, true);

            if (! is_array($row)) {
                throw new InvalidArgumentException(basename($path).' line '.($number + 1).' is not a JSON object.');
            }

            $cases->push(GoldenCase::fromArray($row));
        }

        return $cases;
    }

    private function assertUniqueIds(Collection $cases): void
    {
        $duplicates = $cases->pluck('id')->duplicates();

        if ($duplicates->isNotEmpty()) {
            throw new InvalidArgumentException('Duplicate golden case ids: '.$duplicates->unique()->implode(', ').'.');
        }
    }
}
