<?php

use App\Ai\Hardware\HostProbe;
use App\Ai\Profile\MemorySample;
use App\Ai\Profile\MemorySampler;
use App\Ai\Profile\ProfileSet;
use App\Ai\Profile\ShellMemorySampler;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeHost;

function fakeSampler(): MemorySampler
{
    return new class implements MemorySampler
    {
        private float $started = 0;

        public function start(): void
        {
            $this->started = microtime(true);
        }

        public function stop(): array
        {
            return [new MemorySample($this->started, 3.1, 0.0), new MemorySample($this->started + 1, 43.0, 39.7)];
        }
    };
}

function fakeModelServer(int $sizeVram = 6_000_000_000): void
{
    Http::fake([
        'localhost:11434/v1/chat/completions' => function (Request $request) {
            if ($request['stream'] ?? false) {
                return Http::response(streamBody(['Set ', 'the ', 'schedule.'], usage: ['prompt_tokens' => 1118, 'completion_tokens' => 3]), 200, ['Content-Type' => 'text/event-stream']);
            }

            return Http::response(chatBody('ready', usage: ['prompt_tokens' => 20, 'completion_tokens' => 1]));
        },
        'localhost:11434/api/ps' => Http::response(['models' => [['name' => 'llama3.1:8b', 'size' => 6_000_000_000, 'size_vram' => $sizeVram]]]),
        'localhost:11434/api/show' => Http::response(['details' => ['parameter_size' => '8.0B', 'quantization_level' => 'Q4_K_M'], 'model_info' => ['general.architecture' => 'llama', 'llama.block_count' => 32, 'llama.attention.head_count' => 32, 'llama.attention.head_count_kv' => 8, 'llama.embedding_length' => 4096]]),
        'localhost:11434/api/tags' => Http::response(['models' => [['name' => 'llama3.1:8b', 'size' => 4_900_000_000]]]),
    ]);
}

beforeEach(function () {
    app()->instance(MemorySampler::class, fakeSampler());
    app()->instance(HostProbe::class, FakeHost::mac(128, chip: 'Apple M3 Max'));
});

function profileOutput(array $options = []): array
{
    $status = Artisan::call('ai:profile', $options);

    return [$status, Artisan::output()];
}

it('runs the prompt set against the provider and prints the timeline and the summary', function () {
    fakeModelServer();

    [$status, $output] = profileOutput(['--provider' => 'local']);

    expect($status)->toBe(0)
        ->and($output)->toContain('t(s)  resident GB  gpu GB   phase      prefill tok/s   decode tok/s   ctx tokens')
        ->and($output)->toContain('summary: load-to-first-token')
        ->and($output)->toContain('(M3 Max, ~475 GB/s: predicted 78–116)')       // 475 GB/s over 4.9 GB of weights
        ->and($output)->toContain('peak 43.0 GB')
        ->and($output)->toContain('0 spilled');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/chat/completions')
        && ($request['stream'] ?? false) === true
        && $request['messages'][0]['role'] === 'user');

    expect(ModelCall::where('feature', 'ai.profile')->count())->toBeGreaterThanOrEqual(5);           // the load request and four prompts
});

it('accepts a tier name and refuses a provider that is not set up', function () {
    $this->artisan('ai:profile', ['--provider' => 'mid'])
        ->expectsOutputToContain("The 'local-70b' provider is not set up")
        ->assertFailed();

    Http::assertNothingSent();
});

it('names a missing prompt set instead of crashing', function () {
    fakeModelServer();

    $this->artisan('ai:profile', ['--prompts' => 'storage/samples/missing.json'])
        ->expectsOutputToContain('There is no prompt set at')
        ->assertFailed();
});

it('reports a model server that is not there', function () {
    Http::fake(['localhost:11434/*' => Http::failedConnection()]);

    $this->artisan('ai:profile')
        ->expectsOutputToContain("The 'local' provider did not answer")
        ->assertFailed();
});

it('shows how much of the model Ollama has put on the CPU', function () {
    fakeModelServer(sizeVram: 1_000_000_000);

    [$status, $output] = profileOutput();

    expect($status)->toBe(0)
        ->and($output)->toContain('4.7 GB spilled to CPU');                          // 5 GB of the 6 GB are not on the GPU
});

it('asks for a bandwidth when it does not recognise the machine', function () {
    app()->instance(HostProbe::class, new FakeHost('Linux'));
    fakeModelServer();

    expect(profileOutput()[1])->toContain('no bandwidth known')
        ->and(profileOutput(['--bandwidth' => 100])[1])->toContain('set by --bandwidth, ~100 GB/s');
});

it('builds a sampling loop for the platform with only safe process names', function () {
    $mac = new ShellMemorySampler(FakeHost::mac(), ['ollama', 'llama-server'], 2);
    $nvidia = new ShellMemorySampler(new FakeHost('Linux', ['nvidia-smi -L' => 'GPU 0: NVIDIA GeForce RTX 4090']));

    expect($mac->gpuKind())->toBe('ioreg')
        ->and($mac->script())->toContain('ps -axo rss=,comm=')->toContain('grep -E "ollama|llama-server"')->toContain('IOAccelerator')->toContain('sleep 2')
        ->and($nvidia->gpuKind())->toBe('nvidia')
        ->and($nvidia->script())->toContain('nvidia-smi --query-gpu=memory.used');

    expect(fn () => new ShellMemorySampler(FakeHost::mac(), ['ollama; rm -rf /']))->toThrow(InvalidArgumentException::class);
});

it('starts the loop as a background process and reads back what it printed', function () {
    Process::fake(['*' => Process::describe()->output("@@sample 7\n@@ps\n 1048576 ollama\n@@gpu\n\"In use system memory\"=1073741824\n")->runsFor(0)]);

    $sampler = new ShellMemorySampler(FakeHost::mac());

    $sampler->start();
    $samples = $sampler->stop();

    Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === 'sh' && $process->command[1] === '-c');

    expect($samples)->toHaveCount(1)
        ->and($samples[0]->residentGb)->toBe(1.0)
        ->and($samples[0]->gpuGb)->toBe(1.0);
});

it('ships a prompt set whose articles all exist', function () {
    $prompts = ProfileSet::load(base_path('storage/samples/profile-set.json'));

    expect($prompts)->toHaveCount(4)
        ->and($prompts[0]->text)->toContain('repeat every month')
        ->and(strlen($prompts[1]->text))->toBeGreaterThan(1500);
});
