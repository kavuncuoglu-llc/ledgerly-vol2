<?php

namespace App\Ai\Review;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

// Chapter 50, Topic 9: every decision record whose review date has passed. adr:new writes the header line this reads: `Status: … · Owner: … · Review: YYYY-MM-DD`.
final class DecisionRecords
{
    /** @return Collection<int, array{file: string, title: string, status: string, owner: string, review: ?string, due: bool}> */
    public function all(CarbonImmutable $today): Collection
    {
        $directory = base_path('docs/adr');

        if (! File::isDirectory($directory)) {
            return collect();
        }

        return collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => $this->parse($file->getFilename(), File::get($file->getPathname()), $today))
            ->values();
    }

    /** @return Collection<int, array{file: string, title: string, status: string, owner: string, review: ?string, due: bool}> */
    public function due(CarbonImmutable $today): Collection
    {
        return $this->all($today)->filter(fn (array $record) => $record['due'])->values();
    }

    private function parse(string $file, string $text, CarbonImmutable $today): array
    {
        $title = preg_match('/^#\s+(.+)$/m', $text, $found) === 1
            ? trim($found[1])
            : $file;

        $status = preg_match('/^Status:\s*(.+?)(?:\s·\s|$)/m', $text, $found) === 1
            ? trim($found[1])
            : '';

        $owner = preg_match('/·\s*Owner:\s*(.+?)(?:\s·\s|$)/m', $text, $found) === 1
            ? trim($found[1])
            : '';

        $review = preg_match('/Review:\s*(\d{4}-\d{2}-\d{2})/', $text, $found) === 1
            ? $found[1]
            : null;

        $due = $review !== null && CarbonImmutable::createFromFormat('!Y-m-d', $review)->lessThanOrEqualTo($today);

        return ['file' => 'docs/adr/'.$file, 'title' => $title, 'status' => $status, 'owner' => $owner, 'review' => $review, 'due' => $due];
    }
}
