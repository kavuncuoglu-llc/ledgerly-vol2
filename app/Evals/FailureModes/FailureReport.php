<?php

namespace App\Evals\FailureModes;

use Illuminate\Support\Collection;

// Chapter 38, Figure 38-8: the table the command prints, and the numbers the decision record quotes.
final readonly class FailureReport
{
    /** @param Collection<int, Failure> $failures */
    public function __construct(public Collection $failures) {}

    public function total(): int
    {
        return $this->failures->count();
    }

    public function bySource(): array
    {
        return [
            'golden' => $this->failures->filter(fn (Failure $failure) => $failure->case->source !== 'production')->count(),
            'production' => $this->failures->filter(fn (Failure $failure) => $failure->case->source === 'production')->count(),
        ];
    }

    public function count(FailureBucket $bucket): int
    {
        return $this->failures->filter(fn (Failure $failure) => $failure->bucket === $bucket)->count();
    }

    public function share(FailureBucket $bucket): float
    {
        return $this->total() === 0
            ? 0.0
            : $this->count($bucket) / $this->total();
    }

    /** @return Collection<int, Failure> */
    public function in(FailureBucket $bucket): Collection
    {
        return $this->failures->filter(fn (Failure $failure) => $failure->bucket === $bucket)->values();
    }

    public function lines(): array
    {
        $bySource = $this->bySource();

        $lines = [sprintf('%d failing cases (golden %d, production %d)', $this->total(), $bySource['golden'], $bySource['production'])];

        $lines[] = sprintf('%-31s %5s %7s   %s', 'bucket', 'cases', 'share', 'goes to');

        foreach ($this->ordered() as $bucket) {
            $lines[] = sprintf('%-31s %5d %6d%%   %s', $bucket->value, $this->count($bucket), round($this->share($bucket) * 100), $bucket->goesTo());

            foreach ($this->details($bucket) as $detail => $failures) {
                $lines[] = sprintf('  %-29s %5d %7s   e.g. %s: %s', $detail, $failures->count(), '', $failures->first()->case->id, mb_strimwidth($failures->first()->reason, 0, 70, '…'));
            }
        }

        return $lines;
    }

    public function toArray(): array
    {
        return [
            'failing' => $this->total(),
            'by_source' => $this->bySource(),
            'buckets' => collect($this->ordered())->mapWithKeys(fn (FailureBucket $bucket) => [$bucket->value => ['cases' => $this->count($bucket), 'share' => round($this->share($bucket), 4)]])->all(),
            'cases' => $this->failures->map(fn (Failure $failure) => $failure->toArray())->all(),
        ];
    }

    /** @return list<FailureBucket> */
    private function ordered(): array
    {
        $buckets = FailureBucket::cases();

        usort($buckets, fn (FailureBucket $a, FailureBucket $b) => [$a === FailureBucket::Unclear, $this->count($b)] <=> [$b === FailureBucket::Unclear, $this->count($a)]);

        return $buckets;
    }

    /** @return array<string, Collection<int, Failure>> */
    private function details(FailureBucket $bucket): array
    {
        if ($bucket !== FailureBucket::Behavior) {
            return [];
        }

        return $this->in($bucket)
            ->groupBy(fn (Failure $failure) => $failure->detail)
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->all();
    }
}
