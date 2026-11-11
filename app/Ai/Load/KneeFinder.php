<?php

namespace App\Ai\Load;

// Chapter 24, Topic 7: throughput climbs and then flattens while p99 time to first token stays flat and then climbs sharply. The knee is where p99 crosses the latency you can accept;
// between the last level under the limit and the first over it, the crossing is interpolated in a straight line.
final class KneeFinder
{
    /** @param list<BenchResult> $results in rising concurrency */
    public function find(array $results, float $ttftP99LimitMs): ?float
    {
        $previous = null;

        foreach ($results as $result) {
            if ($result->ttftP99Ms > $ttftP99LimitMs) {
                return $previous === null
                    ? null
                    : $this->between($previous, $result, $ttftP99LimitMs);
            }

            $previous = $result;
        }

        return $previous === null
            ? null
            : (float) $previous->concurrency;                                      // never crossed: the knee is above the highest level tried
    }

    public function crossed(array $results, float $ttftP99LimitMs): bool
    {
        foreach ($results as $result) {
            if ($result->ttftP99Ms > $ttftP99LimitMs) {
                return true;
            }
        }

        return false;
    }

    private function between(BenchResult $under, BenchResult $over, float $limit): float
    {
        $span = $over->ttftP99Ms - $under->ttftP99Ms;

        if ($span <= 0) {
            return (float) $under->concurrency;
        }

        return $under->concurrency + ($over->concurrency - $under->concurrency) * ($limit - $under->ttftP99Ms) / $span;
    }
}
