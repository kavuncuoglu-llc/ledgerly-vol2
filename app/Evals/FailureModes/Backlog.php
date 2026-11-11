<?php

namespace App\Evals\FailureModes;

use Illuminate\Support\Facades\File;

// Chapter 38, Figure 38-8: where the cases that are not fine-tuning problems go. Retrieval misses wait for retrieval tuning, coverage gaps become help-center article requests, and what no check or judge could place waits for a person. One JSON line per case and kind, rewritten in place, so a second run updates the file rather than doubling it. It is storage, not the repository: the lines hold customers' questions.
final class Backlog
{
    public function __construct(private string $path) {}

    public static function default(): self
    {
        return new self(storage_path('eval/backlog.jsonl'));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function record(FailureReport $report): int
    {
        $items = $report->failures
            ->reject(fn (Failure $failure) => $failure->bucket === FailureBucket::Behavior)
            ->reject(fn (Failure $failure) => $failure->bucket === FailureBucket::Arithmetic)
            ->map(fn (Failure $failure) => $this->item($failure))
            ->keyBy(fn (array $item) => $item['kind'].':'.$item['case'])
            ->all();

        $kept = collect($this->read())
            ->reject(fn (array $row) => isset($items[$row['kind'].':'.$row['case']]))
            ->all();

        File::ensureDirectoryExists(dirname($this->path));

        File::put($this->path, collect([...$kept, ...array_values($items)])->map(fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n")."\n");

        return count($items);
    }

    /** @return list<array> */
    public function read(): array
    {
        if (! File::exists($this->path)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        )));
    }

    private function item(Failure $failure): array
    {
        return [
            'kind' => match ($failure->bucket) {
                FailureBucket::Retrieval => 'retrieval',
                FailureBucket::Coverage => 'coverage',
                default => 'review',
            },
            'case' => $failure->case->id,
            'category' => $failure->case->category,
            'question' => $failure->case->question,
            'expected_chunks' => $failure->case->expectedChunks,
            'detail' => $failure->detail,
            'reason' => $failure->reason,
        ];
    }
}
