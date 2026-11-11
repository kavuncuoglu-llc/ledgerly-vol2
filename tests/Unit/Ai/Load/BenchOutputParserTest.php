<?php

use App\Ai\Load\BenchOutputParser;
use App\Ai\Load\BenchResult;
use App\Ai\Load\KneeFinder;

function benchOutput(array $values = []): string
{
    $v = $values + ['ok' => 120, 'failed' => 0, 'rps' => 17.9, 'tok' => 1960.4, 'ttft50' => 610.2, 'ttft99' => 1900.7, 'itl50' => 22.1, 'itl99' => 41.0];

    return <<<TEXT
============ Serving Benchmark Result ============
Successful requests:                     {$v['ok']}
Failed requests:                         {$v['failed']}
Maximum request concurrency:             32
Benchmark duration (s):                  6.71
Total input tokens:                      128400
Total generated tokens:                  13152
Request throughput (req/s):              {$v['rps']}
Output token throughput (tok/s):         {$v['tok']}
Total Token throughput (tok/s):          21094.31
---------------Time to First Token----------------
Mean TTFT (ms):                          701.33
Median TTFT (ms):                        {$v['ttft50']}
P99 TTFT (ms):                           {$v['ttft99']}
-----Time per Output Token (excl. 1st token)------
Mean TPOT (ms):                          21.50
Median TPOT (ms):                        21.10
P99 TPOT (ms):                           40.00
---------------Inter-token Latency----------------
Mean ITL (ms):                           22.40
Median ITL (ms):                         {$v['itl50']}
P99 ITL (ms):                            {$v['itl99']}
==================================================
TEXT;
}

function bench(int $concurrency, float $ttft99, float $tokens = 1000.0): BenchResult
{
    return new BenchResult($concurrency, 100, 0, 10.0, $tokens, 200.0, $ttft99, 10.0, 20.0);
}

it('reads the Serving Benchmark Result block of vllm bench serve', function () {
    $result = (new BenchOutputParser)->parse(benchOutput(), 32);

    expect($result->concurrency)->toBe(32)
        ->and($result->requests)->toBe(120)
        ->and($result->failed)->toBe(0)
        ->and($result->requestsPerSecond)->toBe(17.9)
        ->and($result->outputTokensPerSecond)->toBe(1960.4)
        ->and($result->ttftP50Ms)->toBe(610.2)
        ->and($result->ttftP99Ms)->toBe(1900.7)
        ->and($result->itlP50Ms)->toBe(22.1)
        ->and($result->itlP99Ms)->toBe(41.0);
});

it('reads thousands separators and falls back to TPOT when a version prints no ITL block', function () {
    $output = str_replace('Output token throughput (tok/s):         1960.4', 'Output token throughput (tok/s):         1,960.4', benchOutput());
    $output = preg_replace('/Median ITL.*\nP99 ITL.*\n/', '', $output);

    $result = (new BenchOutputParser)->parse($output, 8);

    expect($result->outputTokensPerSecond)->toBe(1960.4)
        ->and($result->itlP50Ms)->toBe(21.1)
        ->and($result->itlP99Ms)->toBe(40.0);
});

it('fails loudly on output it does not recognise rather than reporting a zero', function () {
    (new BenchOutputParser)->parse("ERROR: could not connect to the server\n", 4);
})->throws(RuntimeException::class, 'printed no "Serving Benchmark Result" block');

it('fails loudly when a percentile is missing', function () {
    (new BenchOutputParser)->parse(preg_replace('/P99 TTFT.*\n/', '', benchOutput()), 4);
})->throws(RuntimeException::class, 'no TTFT percentiles');

describe('the knee', function () {
    it('is where p99 time to first token crosses the limit, interpolated between the two levels', function () {
        $results = [bench(1, 320), bench(8, 450), bench(16, 720), bench(32, 1900), bench(64, 6200)];

        $knee = (new KneeFinder)->find($results, 1000);

        expect($knee)->toBeGreaterThan(16)->toBeLessThan(32)
            ->and(round($knee, 1))->toBe(19.8);
    });

    it('is null when even the lowest level is over the limit', function () {
        expect((new KneeFinder)->find([bench(1, 1500), bench(4, 2500)], 1000))->toBeNull();
    });

    it('says it never crossed when every level is under the limit', function () {
        $finder = new KneeFinder;
        $results = [bench(1, 300), bench(8, 400)];

        expect($finder->find($results, 1000))->toBe(8.0)
            ->and($finder->crossed($results, 1000))->toBeFalse();
    });
});
