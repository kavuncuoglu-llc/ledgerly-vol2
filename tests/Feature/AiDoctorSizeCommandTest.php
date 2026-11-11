<?php

use App\Ai\Hardware\HostProbe;
use App\Ai\Hardware\ModelShape;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHost;

function fakeOllamaModel(string $model = 'llama3.1:70b-instruct-q4_K_M', float $sizeGib = 42.5, int $layers = 80): void
{
    Http::fake([
        'localhost:11434/api/show' => Http::response([
            'details' => ['parameter_size' => '70.6B', 'quantization_level' => 'Q4_K_M'],
            'model_info' => [
                'general.architecture' => 'llama',
                'general.parameter_count' => 70553706560,
                'llama.block_count' => $layers,
                'llama.attention.head_count' => 64,
                'llama.attention.head_count_kv' => 8,
                'llama.embedding_length' => 8192,
            ],
        ]),
        'localhost:11434/api/tags' => Http::response(['models' => [['name' => $model, 'size' => (int) ($sizeGib * ModelShape::BYTES_PER_GIB)]]]),
    ]);
}

it('prints the sum of Figure 21-9 for the 70B on a 128 GB Mac', function () {
    fakeOllamaModel();
    app()->instance(HostProbe::class, FakeHost::mac(128));

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:70b-instruct-q4_K_M', '--ctx' => 8192, '--slots' => 2])
        ->expectsOutputToContain('weights 42.5 GB · KV cache 5.0 GB (fp16; 2.5 GB at q8_0) · headroom 5.7 GB · total 53.2 GB')
        ->expectsOutputToContain('reachable on this machine: 96.0 GB (macOS; iogpu.wired_limit_mb unset → ~75% of 128 GB)')
        ->expectsOutputToContain('✓ fits with 42.8 GB to spare')
        ->assertSuccessful();
});

it('says what to change when the 70B does not fit, and exits non-zero', function () {
    fakeOllamaModel();
    app()->instance(HostProbe::class, FakeHost::mac(64));

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:70b-instruct-q4_K_M', '--slots' => 2])
        ->expectsOutputToContain('✗ does not fit')
        ->expectsOutputToContain('quantize the KV cache to q8_0')
        ->expectsOutputToContain('raise the OS cap on GPU memory, or buy more memory')
        ->assertFailed();
});

it('uses the raised sysctl value as the reachable memory', function () {
    fakeOllamaModel();
    app()->instance(HostProbe::class, FakeHost::mac(64, 56000));

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:70b-instruct-q4_K_M', '--cache' => 'q8_0'])
        ->expectsOutputToContain('reachable on this machine: 56.0 GB (macOS; iogpu.wired_limit_mb=56000 of 64 GB)')
        ->expectsOutputToContain('KV cache 2.5 GB (q8_0)')
        ->expectsOutputToContain('✓ fits')
        ->assertSuccessful();
});

it('sizes from the tag when the server does not answer, and says it estimated', function () {
    Http::fake(['localhost:11434/*' => Http::response('', 404)]);
    app()->instance(HostProbe::class, FakeHost::mac(128));

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:8b', '--ctx' => 8192, '--slots' => 2])
        ->expectsOutputToContain('estimated from the tag (8B, Q4_K_M)')
        ->expectsOutputToContain('KV cache 2.0 GB')
        ->assertSuccessful();
});

it('asks for the attention shape of a model it does not know instead of guessing', function () {
    Http::fake(['localhost:11434/*' => Http::response('', 404)]);
    app()->instance(HostProbe::class, FakeHost::mac(128));

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'qwen3:32b'])
        ->expectsOutputToContain('pass --layers, --kv-heads and --head-dim')
        ->assertFailed();

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'qwen3:32b', '--layers' => 64, '--kv-heads' => 8, '--head-dim' => 128])
        ->expectsOutputToContain('weights 17.9 GB')
        ->assertSuccessful();
});

it('lets --reachable stand in for a machine it cannot read', function () {
    Http::fake(['localhost:11434/*' => Http::response('', 404)]);
    app()->instance(HostProbe::class, new FakeHost('Windows'));

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:8b'])
        ->expectsOutputToContain('Pass --reachable=GB')
        ->assertFailed();

    $this->artisan('ai:doctor', ['--size' => true, '--model' => 'llama3.1:8b', '--reachable' => 8])
        ->expectsOutputToContain('reachable on this machine: 8.0 GB (from --reachable)')
        ->assertSuccessful();
});

it('rejects a cache type it has no size for', function () {
    $this->artisan('ai:doctor', ['--size' => true, '--cache' => 'q2'])
        ->expectsOutputToContain("Unknown cache type 'q2'")
        ->assertFailed();
});
