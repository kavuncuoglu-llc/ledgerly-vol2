<?php

use App\Ai\Load\TrafficShape;
use App\Ai\Prompts\SupportPrompt;
use App\Models\ModelCall;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/ledgerly-load-'.bin2hex(random_bytes(4));

    mkdir($this->directory);

    $this->shape = $this->directory.'/traffic-shape.jsonl';

    file_put_contents($this->shape, implode("\n", array_map(fn (int $i) => json_encode(['prompt_tokens' => 600 + $i * 10, 'completion_tokens' => 80 + $i]), range(1, 30)))."\n");

    config(['ai.providers.vllm.api_key' => 'vllm-test-key', 'ai.vllm.model_repo' => 'RedHatAI/Meta-Llama-3.1-8B-Instruct-FP8']);
});

afterEach(function () {
    removeDirectory($this->directory);
});

function benchBlock(float $ttft99, float $tokens, float $rps): string
{
    return "============ Serving Benchmark Result ============\nSuccessful requests:                     64\nFailed requests:                         0\nRequest throughput (req/s):              {$rps}\nOutput token throughput (tok/s):         {$tokens}\n---------------Time to First Token----------------\nMedian TTFT (ms):                        300.0\nP99 TTFT (ms):                           {$ttft99}\n---------------Inter-token Latency----------------\nMedian ITL (ms):                         12.0\nP99 ITL (ms):                            20.0\n==================================================\n";
}

it('runs vllm bench serve once per level, inside the container by default, and prints the table and the knee', function () {
    Process::fake(function (PendingProcess $process) {
        $command = implode(' ', (array) $process->command);

        return match (true) {
            str_contains($command, '--max-concurrency 4 ') => Process::result(benchBlock(380, 370, 3.4)),
            str_contains($command, '--max-concurrency 16 ') => Process::result(benchBlock(720, 1290, 11.8)),
            str_contains($command, '--max-concurrency 32 ') => Process::result(benchBlock(1900, 1960, 17.9)),
            default => Process::result('', 'unexpected', 1),
        };
    });

    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '4,16,32', '--duration' => 30])
        ->expectsOutputToContain('vllm · 30 request shapes · levels 4, 16, 32')
        ->expectsOutputToContain('1,290')
        ->expectsOutputToContain('p99 over the limit')
        ->expectsOutputToContain('knee ≈ 20 concurrent for TTFT p99 < 1 s; set AI_CONCURRENCY (ai queue processes) to 19')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/vllm.yml --profile vllm exec -T -e OPENAI_API_KEY vllm vllm bench serve')
        && str_contains(implode(' ', (array) $process->command), '--base-url http://localhost:8000')
        && str_contains(implode(' ', (array) $process->command), '--model ledgerly-support')
        && str_contains(implode(' ', (array) $process->command), '--tokenizer RedHatAI/Meta-Llama-3.1-8B-Instruct-FP8'));
});

it('hands the key to the process in the environment and never on a command line', function () {
    Process::fake(['*' => Process::result(benchBlock(300, 400, 4.0))]);

    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '4'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->environment['OPENAI_API_KEY'] ?? null) === 'vllm-test-key');
    Process::assertDidntRun(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'vllm-test-key'));
});

it('writes the prompts the benchmark sends at the size of the traffic, with the real system message in front', function () {
    Process::fake(['*' => Process::result(benchBlock(300, 400, 4.0))]);

    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '4'])->assertSuccessful();

    $lines = file($this->directory.'/traffic-prompts.jsonl', FILE_IGNORE_NEW_LINES);
    $first = json_decode($lines[0], true);

    expect($lines)->toHaveCount(30)
        ->and($first['prompt'])->toStartWith(SupportPrompt::base())
        ->and($first['output_tokens'])->toBe(81)
        ->and(mb_strlen($lines[29]))->toBeGreaterThan(mb_strlen($lines[0]));                  // bigger shape, bigger prompt
});

it('can print the commands without running them', function () {
    Process::fake();

    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '1,8', '--dry-run' => true])
        ->expectsOutputToContain('--max-concurrency 1 ')
        ->expectsOutputToContain('--max-concurrency 8 ')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('runs a vllm from this machine with --runner=local, against VLLM_URL', function () {
    Process::fake(['*' => Process::result(benchBlock(300, 400, 4.0))]);

    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '4', '--runner' => 'local'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ((array) $process->command)[0] === 'vllm' && str_contains(implode(' ', (array) $process->command), '--base-url http://vllm.test:8000 '));
});

it('builds the traffic shape from model_calls with --write-shape, as sizes only', function () {
    Process::fake(['*' => Process::result(benchBlock(300, 400, 4.0))]);

    foreach (range(1, 25) as $i) {
        ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 500 + $i, 'completion_tokens' => 50 + $i, 'finish_reason' => 'stop', 'latency_ms' => 100]);
    }

    ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'semantic-cache', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'finish_reason' => 'cache', 'latency_ms' => 1]);
    ModelCall::create(['feature' => 'ticket.classify', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 80, 'completion_tokens' => 2, 'finish_reason' => 'stop', 'latency_ms' => 100]);

    $path = $this->directory.'/from-calls.jsonl';

    $this->artisan('ai:load', ['--requests' => $path, '--concurrency' => '4', '--write-shape' => true])
        ->expectsOutputToContain('wrote 25 shapes from model_calls')
        ->assertSuccessful();

    $written = file($path, FILE_IGNORE_NEW_LINES);

    expect($written)->toHaveCount(25)
        ->and(json_decode($written[0], true))->toBe(['prompt_tokens' => 525, 'completion_tokens' => 75])           // newest first
        ->and(file_get_contents($path))->not->toContain('content');
});

it('refuses to build a shape from a handful of calls', function () {
    Process::fake();

    ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 500, 'completion_tokens' => 50, 'finish_reason' => 'stop', 'latency_ms' => 100]);

    $this->artisan('ai:load', ['--requests' => $this->directory.'/few.jsonl', '--write-shape' => true])
        ->expectsOutputToContain('model_calls has only 1 completed support.reply requests')
        ->assertFailed();
});

it('refuses a provider that is not vLLM, a missing shape, bad levels, and a failing benchmark', function () {
    Process::fake(['*' => Process::result('', 'Connection refused', 1)]);

    $this->artisan('ai:load', ['--provider' => 'local-llama', '--requests' => $this->shape])->expectsOutputToContain('needs a vLLM provider')->assertFailed();
    $this->artisan('ai:load', ['--requests' => $this->directory.'/nope.jsonl'])->expectsOutputToContain('No traffic shape')->assertFailed();
    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '8,4'])->expectsOutputToContain('rising order')->assertFailed();
    $this->artisan('ai:load', ['--requests' => $this->shape, '--concurrency' => '4'])->expectsOutputToContain('vllm bench serve failed (1): Connection refused')->assertFailed();
});

it('ships a traffic-shape sample, so the command runs before there is any traffic', function () {
    $shapes = app(TrafficShape::class)->read(base_path('storage/benchmarks/traffic-shape.jsonl'));

    expect(count($shapes))->toBeGreaterThanOrEqual(100)
        ->and(min(array_column($shapes, 'prompt_tokens')))->toBeGreaterThanOrEqual(450);          // never smaller than the system message
});
