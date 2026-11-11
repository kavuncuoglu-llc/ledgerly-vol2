<?php

use App\Ai\Hardware\HostProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeHost;

const NVIDIA_QUERY = 'nvidia-smi --query-gpu=name,memory.total,compute_cap --format=csv,noheader,nounits';

function doctorProfile(string $profile, HostProbe $host, array $env = []): array
{
    foreach ($env as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    app()->instance(HostProbe::class, $host);

    $status = Artisan::call('ai:doctor', ['--profile' => $profile]);

    return [$status, Artisan::output()];
}

function gpuServer(string $name = 'NVIDIA L4', int $memoryMib = 23034, string $compute = '8.9', bool $runtime = true, array $globs = []): FakeHost
{
    return new FakeHost('Linux', [
        'docker compose version' => 'Docker Compose version v2.29.7',
        NVIDIA_QUERY => "{$name}, {$memoryMib}, {$compute}",
        "docker info --format '{{json .Runtimes}}'" => $runtime
            ? '{"io.containerd.runc.v2":{},"nvidia":{"path":"nvidia-container-runtime"},"runc":{}}'
            : '{"runc":{}}',
    ], [], $globs + [base_path('ops/compose/vllm.yml') => ['x'], base_path('ops/compose/triton.yml') => ['x']]);
}

afterEach(function () {
    foreach (['VLLM_API_KEY', 'VLLM_REVISION', 'VLLM_MODEL', 'TRITON_API_KEY', 'LLAMA_API_KEY', 'LLAMA_VARIANT', 'LLAMA_MODEL_FILE', 'GRAFANA_ADMIN_PASSWORD', 'VLLM_PORT'] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
});

it('passes the vllm profile on a 24 GB card with the toolkit installed and the variables set', function () {
    [$status, $output] = doctorProfile('vllm', gpuServer(), ['VLLM_API_KEY' => 'k', 'VLLM_REVISION' => 'abc', 'VLLM_MODEL' => 'm', 'VLLM_PORT' => '18991']);

    expect($status)->toBe(0)
        ->and($output)->toContain("Compose profile 'vllm' on this machine (Linux, 1 × NVIDIA L4, 22 GB)")
        ->and($output)->toContain('✓ docker compose v2.29.7')
        ->and($output)->toContain('✓ GPU 0: NVIDIA L4, 22 GB')
        ->and($output)->toContain('✓ Docker has the nvidia runtime')
        ->and($output)->toContain('✓ VLLM_API_KEY is set in .env')
        ->and($output)->toContain('✓ port 18991 on 127.0.0.1 is free')
        ->and($output)->toContain('✓ compute capability 8.9: native FP8 math')
        ->and($output)->toContain('0 warnings, 0 failures');
});

it('fails the vllm profile on a machine with no NVIDIA GPU and points at the cloud script', function () {
    [$status, $output] = doctorProfile('vllm', new FakeHost('Darwin', ['docker compose version' => 'Docker Compose version v2.29.7']), ['VLLM_API_KEY' => 'k', 'VLLM_REVISION' => 'abc', 'VLLM_MODEL' => 'm']);

    expect($status)->toBe(1)
        ->and($output)->toContain('no NVIDIA GPU')
        ->and($output)->toContain('✗ no NVIDIA GPU found')
        ->and($output)->toContain('ops/cloud/gpu-session.sh');
});

it('fails without the container toolkit and says the command, and fails on a missing key or revision', function () {
    [$status, $output] = doctorProfile('vllm', gpuServer(runtime: false), ['VLLM_API_KEY' => '', 'VLLM_REVISION' => '', 'VLLM_MODEL' => 'm']);

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ Docker has no nvidia runtime')
        ->and($output)->toContain('nvidia-ctk runtime configure')
        ->and($output)->toContain('✗ VLLM_API_KEY is empty in .env')
        ->and($output)->toContain('✗ VLLM_REVISION is empty in .env');
});

it('warns about a small card and about a card with no native FP8, and fails a tiny one', function () {
    $env = ['VLLM_API_KEY' => 'k', 'VLLM_REVISION' => 'abc', 'VLLM_MODEL' => 'm'];

    [$status, $output] = doctorProfile('vllm', gpuServer('NVIDIA T4', 15360, '7.5'), $env);

    expect($status)->toBe(0)
        ->and($output)->toContain('⚠ GPU 0: NVIDIA T4, 15 GB')
        ->and($output)->toContain('⚠ compute capability 7.5 has no native FP8');

    [$status, $output] = doctorProfile('vllm', gpuServer('NVIDIA GTX 1650', 4096, '7.5'), $env);

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ GPU 0: NVIDIA GTX 1650, 4 GB: too small');
});

it('warns when the published port is already taken', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    [, $output] = doctorProfile('vllm', gpuServer(), ['VLLM_API_KEY' => 'k', 'VLLM_REVISION' => 'abc', 'VLLM_MODEL' => 'm', 'VLLM_PORT' => (string) $port]);

    fclose($server);

    expect($output)->toContain("⚠ port {$port} on 127.0.0.1 is already in use (VLLM_PORT)");
});

it('checks the llama profile for the pinned GGUF and a key, and the triton profile for its key and the ONNX files', function () {
    [$status, $output] = doctorProfile('llama', new FakeHost('Linux', ['docker compose version' => 'Docker Compose version v2.29.7']), ['LLAMA_API_KEY' => '']);

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ models/ledgerly-support-q4_k_m.gguf is missing: run ./ops/llama/pull.sh')
        ->and($output)->toContain('✗ LLAMA_API_KEY is empty');

    [$status, $output] = doctorProfile('triton', gpuServer(), ['TRITON_API_KEY' => 'k']);

    expect($status)->toBe(0)
        ->and($output)->toContain('⚠ ops/triton/models/reranker and embedder/1/model.onnx is not there')
        ->and($output)->toContain('ops/triton/export_onnx.py');
});

it('knows the Mac cannot give Docker the GPU, and the monitoring profile needs a Grafana password', function () {
    [, $output] = doctorProfile('ollama', new FakeHost('Darwin', ['docker compose version' => 'Docker Compose version v2.29.7']));

    expect($output)->toContain('⚠ Docker on a Mac cannot use Metal');

    [$status, $output] = doctorProfile('monitoring', new FakeHost('Linux', ['docker compose version' => 'Docker Compose version v2.29.7']), ['GRAFANA_ADMIN_PASSWORD' => '']);

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ GRAFANA_ADMIN_PASSWORD is empty in .env');
});

it('rejects a profile it does not know, and fails when docker compose is missing', function () {
    [$status, $output] = doctorProfile('bogus', gpuServer());

    expect($status)->toBe(1)
        ->and($output)->toContain("Unknown profile 'bogus'");

    [$status, $output] = doctorProfile('ollama', new FakeHost('Linux'));

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ Docker Compose is not available');
});
