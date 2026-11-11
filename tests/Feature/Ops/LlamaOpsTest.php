<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

it('builds llama-server from a pinned release in both images, and the pin is one value everywhere', function () {
    $tag = envExampleValue('LLAMA_TAG');
    $compose = file_get_contents(base_path('ops/compose/llama.yml'));

    expect($tag)->toMatch('/^b\d+$/');

    foreach (['cpu', 'cuda'] as $variant) {
        $dockerfile = file_get_contents(base_path("ops/llama/Dockerfile.{$variant}"));

        expect($dockerfile)->toContain("ARG LLAMA_TAG={$tag}")
            ->and($dockerfile)->toContain('--branch ${LLAMA_TAG}')
            ->and($dockerfile)->toContain('ENTRYPOINT ["llama-server"')
            ->and($dockerfile)->not->toMatch('/--branch (master|main)/');
    }

    expect($compose)->toContain('${LLAMA_TAG:-'.$tag.'}')
        ->and(file_get_contents(base_path('ops/llama/Dockerfile.cuda')))->toContain('-DGGML_CUDA=ON')->toContain('nvidia/cuda:12.6.0-devel-ubuntu24.04')->toContain('nvidia/cuda:12.6.0-runtime-ubuntu24.04')
        ->and(file_get_contents(base_path('ops/llama/Dockerfile.cpu')))->toContain('-DGGML_NATIVE=ON')->not->toContain('GGML_CUDA');
});

it('runs llama-server with the flags of Figure 23-5, a required key, and a port on loopback', function () {
    $service = Yaml::parseFile(base_path('ops/compose/llama.yml'))['services']['llama'];
    $command = implode(' ', $service['command']);

    expect($service['profiles'])->toBe(['llama'])
        ->and($service['ports'])->toBe(['127.0.0.1:${LLAMA_PORT:-8080}:8080'])
        ->and($service['networks']['internal']['aliases'])->toBe(['llama'])
        ->and($service['volumes'])->toBe(['../../models:/models:ro'])
        ->and($command)->toContain('-m /models/${LLAMA_MODEL_FILE:-ledgerly-support-q4_k_m.gguf}')
        ->and($command)->toContain('-c ${LLAMA_CTX:-32768} -np ${LLAMA_PARALLEL:-4}')
        ->and($command)->toContain('-ngl ${LLAMA_NGL:-99} -fa ${LLAMA_FLASH_ATTN:-on}')
        ->and($command)->toContain('-ctk ${LLAMA_CACHE_TYPE:-q8_0} -ctv ${LLAMA_CACHE_TYPE:-q8_0}')
        ->and($command)->toContain('--metrics --api-key ${LLAMA_API_KEY:?')                    // no default: an empty key would switch the auth off
        ->and($service['healthcheck']['test'])->toContain('http://localhost:8080/health')
        ->and($service['healthcheck']['start_period'])->toBe('120s')
        ->and(Yaml::parseFile(base_path('ops/compose/llama.gpu.yml'))['services']['llama']['deploy']['resources']['reservations']['devices'][0])->toBe(['driver' => 'nvidia', 'count' => 1, 'capabilities' => ['gpu']]);
});

it('does not put the key-requiring file in the root compose.yaml, which would stop Chapter 0 for everyone', function () {
    $root = Yaml::parseFile(base_path('compose.yaml'));

    expect($root['include'][0]['path'])->not->toContain('ops/compose/llama.yml');
});

it('commits no key: LLAMA_API_KEY is empty in both env examples and nothing sets a default', function () {
    expect(envExampleValue('LLAMA_API_KEY'))->toBe('')
        ->and(file_get_contents(base_path('ops/compose/.env.example')))->toBe(file_get_contents(base_path('.env.example')))
        ->and(file_get_contents(base_path('ops/compose/llama.yml')))->not->toMatch('/LLAMA_API_KEY:-/')
        ->and(file_get_contents(base_path('ops/llama/pull.sh')))->not->toMatch('/hf_[A-Za-z0-9]{20,}/');
});

it('keeps models out of git', function () {
    expect(file_get_contents(base_path('.gitignore')))->toContain('/models/*.gguf');
});

describe('ops/llama/pull.sh', function () {
    beforeEach(function () {
        if (Process::run('command -v bash')->failed()) {
            $this->markTestSkipped('bash is not available');
        }
    });

    it('parses', function () {
        expect(Process::run(['bash', '-n', base_path('ops/llama/pull.sh')])->successful())->toBeTrue();
    });

    it('pins every model to a commit and a SHA-256', function () {
        preg_match_all('/^  "(\w+)\|([^|]+)\|([0-9a-f]{40})\|([^|]+)\|([^|]+)\|([0-9a-f]{64})\|(\d+)"$/m', file_get_contents(base_path('ops/llama/pull.sh')), $rows, PREG_SET_ORDER);

        expect(collect($rows)->pluck(1)->all())->toBe(['chat', 'draft', 'cpu'])
            ->and(collect($rows)->pluck(5)->all())->toBe(['ledgerly-support-q4_k_m.gguf', 'llama-3.2-1b-instruct-q8_0.gguf', 'gemma-3-4b-it-q6_k.gguf']);
    });

    it('checks what is there without downloading', function () {
        $directory = sys_get_temp_dir().'/ledgerly-models-'.bin2hex(random_bytes(4));

        mkdir($directory);

        file_put_contents($directory.'/ledgerly-support-q4_k_m.gguf', 'not the pinned file');

        $result = Process::env(['MODELS_DIR' => $directory])->run(['bash', base_path('ops/llama/pull.sh'), '--check']);

        removeDirectory($directory);

        expect($result->failed())->toBeTrue()
            ->and($result->output())->toContain('missing  llama-3.2-1b-instruct-q8_0.gguf')
            ->and($result->errorOutput())->toContain('MISMATCH ledgerly-support-q4_k_m.gguf');
    });

    it('deletes a download that does not match the pinned checksum', function () {
        $directory = sys_get_temp_dir().'/ledgerly-models-'.bin2hex(random_bytes(4));
        $mirror = $directory.'/mirror/bartowski/Meta-Llama-3.1-8B-Instruct-GGUF/resolve/bf5b95e96dac0462e2a09145ec66cae9a3f12067';

        mkdir($mirror, 0777, true);
        file_put_contents($mirror.'/Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf', 'a different file');

        $result = Process::env(['MODELS_DIR' => $directory.'/models', 'HF_BASE' => 'file://'.$directory.'/mirror'])->run(['bash', base_path('ops/llama/pull.sh'), 'chat']);

        $left = glob($directory.'/models/*');

        removeDirectory($directory);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('does not match the pinned SHA-256')
            ->and($left)->toBe([]);
    });

    it('rejects a target it does not know', function () {
        $result = Process::run(['bash', base_path('ops/llama/pull.sh'), 'bogus']);

        expect($result->exitCode())->toBe(2);
    });
});
