<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Load\BenchOutputParser;
use App\Ai\Load\BenchResult;
use App\Ai\Load\KneeFinder;
use App\Ai\Load\TrafficShape;
use App\Ai\Load\VllmBenchRunner;
use Illuminate\Console\Command;
use RuntimeException;

class AiLoad extends Command
{
    protected $signature = 'ai:load
        {--provider=vllm : A vLLM provider from config/ai.php}
        {--requests=storage/benchmarks/traffic-shape.jsonl : The traffic shape: one {"prompt_tokens": N, "completion_tokens": M} per line}
        {--concurrency=1,4,8,16,32,64 : Simultaneous streams to try, in rising order}
        {--duration=120 : Seconds to hold each level (turned into a number of requests)}
        {--ttft-p99=1000 : The p99 time to first token, in ms, the knee is measured against}
        {--runner=docker : docker (inside the vllm container) or local (a vllm on this machine)}
        {--write-shape : Build the traffic shape from model_calls first}
        {--feature=support.reply : The model_calls feature --write-shape reads}
        {--limit=500 : Requests --write-shape keeps}
        {--dry-run : Print the commands and stop}';

    protected $description = 'Find the concurrency knee of a vLLM server with `vllm bench serve` on the size of the app\'s own requests (Chapter 24)';

    public function handle(AiManager $ai, TrafficShape $traffic, KneeFinder $knees): int
    {
        try {
            $provider = $ai->config((string) $this->option('provider'));
        } catch (ProviderNotConfigured $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (($provider['native'] ?? null) !== 'vllm') {
            $this->error("ai:load drives `vllm bench serve`, so it needs a vLLM provider; '{$this->option('provider')}' is not one.");

            return self::FAILURE;
        }

        $levels = $this->levels();

        if ($levels === []) {
            $this->error('--concurrency takes numbers in rising order, for example 1,4,8,16.');

            return self::FAILURE;
        }

        $runner = (string) $this->option('runner');

        if (! in_array($runner, ['docker', 'local'], true)) {
            $this->error("--runner is docker or local, not '{$runner}'.");

            return self::FAILURE;
        }

        try {
            $shapes = $this->shapes($traffic);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = $this->shapePath();
        $dataset = dirname($path).'/traffic-prompts.jsonl';

        $traffic->writeDataset($dataset, $shapes);

        $bench = new VllmBenchRunner(
            parser: new BenchOutputParser,
            provider: $provider,
            runner: $runner,
            localDatasetPath: $dataset,
            completionTokens: $traffic->medianCompletion($shapes),
            tokenizer: (string) config('ai.vllm.model_repo'),
        );

        $this->line(sprintf('%s · %d request shapes · levels %s · each held about %d s · `vllm bench serve` %s', $this->option('provider'), count($shapes), implode(', ', $levels), (int) $this->option('duration'), $bench->describe()));

        if ($this->option('dry-run')) {
            foreach ($levels as $level) {
                $this->line(implode(' ', $bench->command($level, $this->promptsFor($level))));
            }

            return self::SUCCESS;
        }

        return $this->measure($bench, $levels, $knees);
    }

    /** @param list<int> $levels */
    private function measure(VllmBenchRunner $bench, array $levels, KneeFinder $knees): int
    {
        $results = [];

        foreach ($levels as $level) {
            $this->line("  concurrency {$level} ...");

            try {
                $results[] = $bench->run($level, $this->promptsFor($level));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $limit = (float) $this->option('ttft-p99');

        $this->table(
            ['concurrency', 'req/s', 'out tok/s', 'TTFT p50', 'TTFT p99', 'ITL p50', 'ITL p99', 'notes'],
            array_map(fn (BenchResult $result, int $index) => $this->row($result, $results[$index - 1] ?? null, $limit), $results, array_keys($results)),
        );

        $this->knee($results, $knees, $limit);

        return self::SUCCESS;
    }

    /** @param list<BenchResult> $results */
    private function knee(array $results, KneeFinder $knees, float $limit): void
    {
        $knee = $knees->find($results, $limit);

        $seconds = rtrim(rtrim(number_format($limit / 1000, 1), '0'), '.');

        if ($knee === null) {
            $this->warn("Even the lowest level is over TTFT p99 {$seconds} s. Nothing to size the queue against: look at the server (prefill, quantization, --max-num-batched-tokens) first.");

            return;
        }

        if (! $knees->crossed($results, $limit)) {
            $this->line(sprintf('p99 TTFT never crossed %s s up to concurrency %d: the knee is higher. Try more levels.', $seconds, (int) $knee));

            return;
        }

        $this->line(sprintf('knee ≈ %d concurrent for TTFT p99 < %s s; set AI_CONCURRENCY (ai queue processes) to %d', (int) round($knee), $seconds, max(1, (int) floor($knee))));
    }

    private function row(BenchResult $result, ?BenchResult $previous, float $limit): array
    {
        $notes = [];

        if ($result->failed > 0) {
            $notes[] = "{$result->failed} failed";
        }

        if ($result->ttftP99Ms > $limit) {
            $notes[] = 'p99 over the limit';
        }

        if ($previous !== null && $result->outputTokensPerSecond < $previous->outputTokensPerSecond * 1.08) {
            $notes[] = 'throughput flat: saturated';
        }

        return [
            $result->concurrency,
            number_format($result->requestsPerSecond, 1),
            number_format($result->outputTokensPerSecond),
            $this->ms($result->ttftP50Ms),
            $this->ms($result->ttftP99Ms),
            $this->ms($result->itlP50Ms),
            $this->ms($result->itlP99Ms),
            $notes === []
                ? ''
                : '← '.implode('; ', $notes),
        ];
    }

    private function ms(float $value): string
    {
        return number_format((int) round($value)).' ms';
    }

    private function shapes(TrafficShape $traffic): array
    {
        $path = $this->shapePath();

        if (! $this->option('write-shape')) {
            return $traffic->read($path);
        }

        $shapes = $traffic->fromCalls((string) $this->option('feature'), (int) $this->option('limit'));

        if (count($shapes) < 20) {
            throw new RuntimeException(sprintf('model_calls has only %d completed %s requests; ai:load needs a few dozen to be a shape. Use the sample at storage/benchmarks/traffic-shape.jsonl, or run the replay first (conversation:replay).', count($shapes), $this->option('feature')));
        }

        $traffic->write($path, $shapes);

        $this->line(sprintf('wrote %d shapes from model_calls to %s', count($shapes), $this->option('requests')));

        return $shapes;
    }

    private function shapePath(): string
    {
        $path = (string) $this->option('requests');

        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    // vllm bench has no duration flag: a level is held by sending enough requests, a fixed number per second of the duration asked for, never fewer than four per stream.
    private function promptsFor(int $concurrency): int
    {
        return max($concurrency * 4, $concurrency * (int) ceil((int) $this->option('duration') / 15));
    }

    /** @return list<int> */
    private function levels(): array
    {
        $levels = array_map('intval', array_filter(explode(',', (string) $this->option('concurrency')), 'strlen'));

        $sorted = $levels;

        sort($sorted);

        if ($levels === [] || $levels !== $sorted || min($levels) < 1) {
            return [];
        }

        return array_values(array_unique($levels));
    }
}
