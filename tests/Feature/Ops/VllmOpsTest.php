<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

function vllmService(): array
{
    return Yaml::parseFile(base_path('ops/compose/vllm.yml'))['services']['vllm'];
}

it('pins the vLLM image, the model and its revision, and never says latest', function () {
    $service = vllmService();
    $compose = file_get_contents(base_path('ops/compose/vllm.yml'));

    expect($service['image'])->toBe('${VLLM_IMAGE:-vllm/vllm-openai:v0.32.0}')
        ->and($service['image'])->not->toContain('latest')
        ->and(envExampleValue('VLLM_IMAGE'))->toMatch('#^vllm/vllm-openai:v\d+\.\d+\.\d+$#')
        ->and(envExampleValue('VLLM_REVISION'))->toMatch('/^[0-9a-f]{40}$/')                 // a commit, not a branch
        ->and(envExampleValue('VLLM_MID_REVISION'))->toMatch('/^[0-9a-f]{40}$/')
        ->and($compose)->not->toMatch('/--revision\s*\n\s*- "[^$]/')                           // no literal revision: it comes from .env and is required
        ->and(implode(' ', $service['command']))->toContain('${VLLM_REVISION:?');
});

it('runs vLLM with the flags of Figure 24-5, a required key, and a port on loopback', function () {
    $service = vllmService();
    $command = implode(' ', $service['command']);

    expect($service['profiles'])->toBe(['vllm'])
        ->and($service['ports'])->toBe(['127.0.0.1:${VLLM_PORT:-8001}:8000'])
        ->and($service['networks']['internal']['aliases'])->toBe(['vllm'])
        ->and($service['ipc'])->toBe('host')
        ->and($service['volumes'])->toContain('hf-cache:/root/.cache/huggingface')
        ->and($command)->toContain('--served-model-name ${VLLM_SERVED_MODEL:-ledgerly-support}')
        ->and($command)->toContain('--max-model-len ${VLLM_MAX_MODEL_LEN:-8192}')
        ->and($command)->toContain('--gpu-memory-utilization ${VLLM_GPU_UTILIZATION:-0.92}')
        ->and($command)->toContain('--max-num-seqs ${VLLM_MAX_NUM_SEQS:-32}')
        ->and($command)->toContain('--enable-prefix-caching --enable-auto-tool-choice --tool-call-parser ${VLLM_TOOL_PARSER:-llama3_json}')
        ->and($command)->toContain('--api-key ${VLLM_API_KEY:?')                                // no default: an empty key would switch the auth off
        ->and($service['healthcheck']['test'][0])->toBe('CMD-SHELL')
        ->and($service['healthcheck']['test'][1])->toContain('http://localhost:8000/v1/chat/completions')      // Chapter 25: health that means loaded, a real one-token completion
        ->and($service['healthcheck']['test'][1])->toContain('max_tokens')
        ->and($service['healthcheck']['test'][1])->toContain('$$VLLM_API_KEY')                                 // read from the container's environment, never written into the file
        ->and($service['healthcheck']['timeout'])->toBe('20s')
        ->and($service['healthcheck']['start_period'])->toBe('300s')
        ->and($service['deploy']['resources']['reservations']['devices'][0])->toBe(['driver' => 'nvidia', 'count' => 1, 'capabilities' => ['gpu']]);
});

it('takes HF_TOKEN from the shell and keeps it out of every file', function () {
    $service = vllmService();

    expect($service['environment'])->toBe(['HF_TOKEN', 'VLLM_API_KEY', 'VLLM_SERVED_MODEL']);   // names with no value: Compose copies them from the shell and .env

    foreach ([base_path('ops/compose/vllm.yml'), base_path('.env.example'), base_path('ops/cloud/gpu-session.sh')] as $file) {
        expect(file_get_contents($file))->not->toMatch('/hf_[A-Za-z0-9]{20,}/');
    }

    expect(envExampleValue('HF_TOKEN'))->toBe('');
});

it('does not put the key-requiring file in the root compose.yaml, which would stop Chapter 0 for everyone without a GPU', function () {
    $root = Yaml::parseFile(base_path('compose.yaml'));

    expect($root['include'][0]['path'])->not->toContain('ops/compose/vllm.yml');
});

it('commits no key: VLLM_API_KEY is empty in both env examples and nothing sets a default', function () {
    expect(envExampleValue('VLLM_API_KEY'))->toBe('')
        ->and(file_get_contents(base_path('ops/compose/.env.example')))->toBe(file_get_contents(base_path('.env.example')))
        ->and(file_get_contents(base_path('ops/compose/vllm.yml')))->not->toMatch('/VLLM_API_KEY:-/');
});

it('can be run by a developer without a GPU as a config check: the file is valid Compose', function () {
    if (Process::run('docker compose version')->failed()) {
        $this->markTestSkipped('docker compose is not available');
    }

    $result = Process::env(['VLLM_API_KEY' => 'test-key', 'VLLM_REVISION' => envExampleValue('VLLM_REVISION'), 'VLLM_MODEL' => envExampleValue('VLLM_MODEL'), 'VLLM_MAX_MODEL_LEN' => '8192', 'VLLM_MAX_NUM_SEQS' => '32', 'VLLM_TOOL_PARSER' => 'llama3_json', 'HF_TOKEN' => ''])->run(['docker', 'compose', '--env-file', base_path('.env.example'), '-f', base_path('ops/compose/base.yml'), '-f', base_path('ops/compose/vllm.yml'), '--profile', 'vllm', 'config', '--quiet']);

    expect($result->successful())->toBeTrue($result->errorOutput());
});

describe('ops/cloud/gpu-session.sh', function () {
    beforeEach(function () {
        if (Process::run('command -v bash')->failed()) {
            $this->markTestSkipped('bash is not available');
        }
    });

    it('parses and is executable', function () {
        expect(Process::run(['bash', '-n', base_path('ops/cloud/gpu-session.sh')])->successful())->toBeTrue()
            ->and(is_executable(base_path('ops/cloud/gpu-session.sh')))->toBeTrue();
    });

    it('has the --down that Appendix L.7 promises, and the steps in the order it lists', function () {
        $script = file_get_contents(base_path('ops/cloud/gpu-session.sh'));

        expect($script)->toContain('--down)')
            ->and(strpos($script, 'check_driver'))->toBeLessThan(strpos($script, 'install_container_toolkit'))
            ->and(strpos($script, "      install_container_toolkit\n      write_env"))->not->toBeFalse()
            ->and($script)->toContain('print_doctor')
            ->and($script)->toContain('openssl rand -hex 24');
    });

    it('stops at the driver check on a machine with no NVIDIA GPU, before it changes anything', function () {
        if (Process::run('command -v nvidia-smi')->successful()) {
            $this->markTestSkipped('this machine has an NVIDIA GPU');
        }

        $result = Process::path(base_path())->run(['bash', base_path('ops/cloud/gpu-session.sh')]);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain('nvidia-smi not found');
    });

    it('prints its usage and rejects an option it does not know', function () {
        expect(Process::run(['bash', base_path('ops/cloud/gpu-session.sh'), '--help'])->output())->toContain('--down')
            ->and(Process::run(['bash', base_path('ops/cloud/gpu-session.sh'), '--bogus'])->exitCode())->toBe(2);
    });

    it('passes shellcheck when shellcheck is installed', function () {
        if (Process::run('command -v shellcheck')->failed()) {
            $this->markTestSkipped('shellcheck is not installed');
        }

        $result = Process::run(['shellcheck', base_path('ops/cloud/gpu-session.sh')]);

        expect($result->successful())->toBeTrue($result->output());
    });
});
