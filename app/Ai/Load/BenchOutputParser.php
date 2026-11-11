<?php

namespace App\Ai\Load;

use RuntimeException;

// Reads the "Serving Benchmark Result" block that `vllm bench serve` prints. The labels are the ones its recent versions print; a label that is missing is a loud failure, never a zero.
final class BenchOutputParser
{
    public function parse(string $output, int $concurrency): BenchResult
    {
        if (! str_contains($output, 'Serving Benchmark Result')) {
            throw new RuntimeException('vllm bench serve printed no "Serving Benchmark Result" block. Its last lines were: '.trim(implode(' | ', array_slice(preg_split('/\R/', trim($output)) ?: [], -3))));
        }

        $itl = $this->percentiles($output, 'ITL') ?? $this->percentiles($output, 'TPOT');

        if ($itl === null) {
            throw new RuntimeException('The benchmark output has neither ITL nor TPOT percentiles; run it with --percentile-metrics ttft,tpot,itl.');
        }

        $ttft = $this->percentiles($output, 'TTFT') ?? throw new RuntimeException('The benchmark output has no TTFT percentiles; run it with --percentile-metrics ttft,tpot,itl.');

        return new BenchResult(
            concurrency: $concurrency,
            requests: (int) $this->number($output, 'Successful requests'),
            failed: (int) ($this->number($output, 'Failed requests') ?? 0),
            requestsPerSecond: $this->number($output, 'Request throughput \(req\/s\)') ?? throw new RuntimeException('The benchmark output has no request throughput.'),
            outputTokensPerSecond: $this->number($output, 'Output token throughput \(tok\/s\)') ?? throw new RuntimeException('The benchmark output has no output token throughput.'),
            ttftP50Ms: $ttft[0],
            ttftP99Ms: $ttft[1],
            itlP50Ms: $itl[0],
            itlP99Ms: $itl[1],
        );
    }

    /** @return array{0: float, 1: float}|null p50 and p99 in milliseconds */
    private function percentiles(string $output, string $metric): ?array
    {
        $p50 = $this->number($output, "Median {$metric} \\(ms\\)") ?? $this->number($output, "P50 {$metric} \\(ms\\)");
        $p99 = $this->number($output, "P99 {$metric} \\(ms\\)");

        if ($p50 === null || $p99 === null) {
            return null;
        }

        return [$p50, $p99];
    }

    private function number(string $output, string $label): ?float
    {
        if (preg_match('/^\s*'.$label.':\s+([0-9][0-9,]*\.?[0-9]*)/mi', $output, $match) !== 1) {
            return null;
        }

        return (float) str_replace(',', '', $match[1]);
    }
}
