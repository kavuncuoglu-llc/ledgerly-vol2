<?php

namespace App\Ai\Load;

use Illuminate\Support\Facades\Process;
use RuntimeException;

// Chapter 24, Topic 7: ai:load does not reimplement a load generator; it runs `vllm bench serve` once per concurrency level, inside the vllm container (the default) or from a vllm on this machine's PATH.
// The API key goes to the process in OPENAI_API_KEY (which is how vllm bench authenticates), never on a command line where `ps` would show it.
final class VllmBenchRunner
{
    public const DATASET_IN_CONTAINER = '/benchmarks/traffic-prompts.jsonl';

    public function __construct(
        private BenchOutputParser $parser,
        private array $provider,
        private string $runner,
        private string $localDatasetPath,
        private int $completionTokens,
        private string $tokenizer = '',
    ) {}

    public function command(int $concurrency, int $prompts): array
    {
        $bench = [
            'vllm', 'bench', 'serve',
            '--backend', 'openai-chat',
            '--base-url', $this->baseUrl(),
            '--endpoint', '/v1/chat/completions',
            '--model', (string) $this->provider['model'],
            '--dataset-name', 'custom',
            '--dataset-path', $this->datasetPath(),
            '--custom-output-len', (string) $this->completionTokens,
            '--num-prompts', (string) $prompts,
            '--max-concurrency', (string) $concurrency,
            '--percentile-metrics', 'ttft,tpot,itl,e2el',
            '--metric-percentiles', '50,99',
            '--seed', '42',
        ];

        if ($this->tokenizer !== '') {
            array_push($bench, '--tokenizer', $this->tokenizer);
        }

        return $this->runner === 'docker'
            ? [...$this->compose(), 'exec', '-T', '-e', 'OPENAI_API_KEY', 'vllm', ...$bench]
            : $bench;
    }

    public function run(int $concurrency, int $prompts): BenchResult
    {
        $result = Process::env(['OPENAI_API_KEY' => (string) ($this->provider['api_key'] ?? '')])
            ->path(base_path())
            ->timeout(3600)
            ->run($this->command($concurrency, $prompts));

        if ($result->failed()) {
            throw new RuntimeException('vllm bench serve failed ('.$result->exitCode().'): '.trim(strtok($result->errorOutput() ?: $result->output(), "\n") ?: 'no output'));
        }

        return $this->parser->parse($result->output(), $concurrency);
    }

    public function describe(): string
    {
        return $this->runner === 'docker'
            ? 'inside the vllm container (docker compose exec)'
            : 'from the vllm on this machine';
    }

    // Inside the container the server is on its own port 8000; from this machine it is wherever VLLM_URL points.
    private function baseUrl(): string
    {
        return $this->runner === 'docker'
            ? 'http://localhost:8000'
            : preg_replace('#/v1/?$#', '', rtrim((string) $this->provider['base_url'], '/'));
    }

    private function datasetPath(): string
    {
        return $this->runner === 'docker'
            ? self::DATASET_IN_CONTAINER
            : $this->localDatasetPath;
    }

    private function compose(): array
    {
        return ['docker', 'compose', '--env-file', '.env', '-f', 'ops/compose/base.yml', '-f', 'ops/compose/vllm.yml', '--profile', 'vllm'];
    }
}
