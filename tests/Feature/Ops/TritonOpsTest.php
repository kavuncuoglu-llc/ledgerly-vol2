<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

function modelConfig(string $model): array
{
    $path = base_path("ops/triton/models/{$model}/config.pbtxt");

    expect(is_file($path))->toBeTrue("ops/triton/models/{$model}/config.pbtxt is missing");

    return parsePbtxt(file_get_contents($path));
}

function tensors(array $config, string $direction): array
{
    $names = [];

    foreach ($config[$direction] ?? [] as $tensor) {
        $names[$tensor['name'][0]] = ['type' => $tensor['data_type'][0], 'dims' => $tensor['dims']];
    }

    return $names;
}

describe('the model repository of Figure 25-2', function () {
    it('has the four models and a numbered version directory for each', function () {
        foreach (['reranker', 'embedder', 'chat', 'support_pipeline'] as $model) {
            expect(is_dir(base_path("ops/triton/models/{$model}/1")))->toBeTrue("{$model}/1");
        }
    });

    it('declares the reranker exactly as the book does', function () {
        $config = modelConfig('reranker');

        expect($config['name'][0])->toBe('reranker')
            ->and($config['backend'][0])->toBe('onnxruntime')
            ->and($config['max_batch_size'][0])->toBe(64)
            ->and(tensors($config, 'input'))->toBe(['input_ids' => ['type' => 'TYPE_INT64', 'dims' => [-1]], 'attention_mask' => ['type' => 'TYPE_INT64', 'dims' => [-1]]])
            ->and(tensors($config, 'output'))->toBe(['logits' => ['type' => 'TYPE_FP32', 'dims' => [1]]])
            ->and($config['dynamic_batching'][0]['max_queue_delay_microseconds'][0])->toBe(2000)                 // batch pairs that arrive within 2 ms
            ->and($config['instance_group'][0]['count'][0])->toBe(2)
            ->and($config['instance_group'][0]['kind'][0])->toBe('KIND_GPU')
            ->and($config['instance_group'][0]['gpus'])->toBe([0])
            ->and($config['version_policy'][0]['latest'][0]['num_versions'][0])->toBe(1);
    });

    it('declares the embedder with the output the ensemble maps', function () {
        $config = modelConfig('embedder');

        expect($config['backend'][0])->toBe('onnxruntime')
            ->and(array_keys(tensors($config, 'input')))->toBe(['input_ids', 'attention_mask'])
            ->and(tensors($config, 'output'))->toBe(['embedding' => ['type' => 'TYPE_FP32', 'dims' => [768]]])      // nomic-embed-text: config('ai.embed_dimensions')
            ->and($config['dynamic_batching'][0]['max_queue_delay_microseconds'][0])->toBe(2000);
    });

    it('declares the chat model for the vLLM backend, and the engine arguments in model.json match the Compose profile', function () {
        $config = modelConfig('chat');
        $engine = json_decode(file_get_contents(base_path('ops/triton/models/chat/1/model.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($config['backend'][0])->toBe('vllm')
            ->and($config['instance_group'][0]['kind'][0])->toBe('KIND_MODEL')
            ->and($engine['model'])->toBe(envExampleValue('VLLM_MODEL'))
            ->and($engine['revision'])->toBe(envExampleValue('VLLM_REVISION'))                                       // pinned, and the same pin as the vllm profile
            ->and($engine['revision'])->toMatch('/^[0-9a-f]{40}$/')
            ->and($engine['max_model_len'])->toBe((int) config('ai.budget.window'))
            ->and($engine['enable_prefix_caching'])->toBeTrue();
    });

    it('chains the embedder and the reranker in an ensemble whose maps are consistent with the models they name', function () {
        $ensemble = modelConfig('support_pipeline');

        expect($ensemble['platform'][0])->toBe('ensemble')
            ->and($ensemble['max_batch_size'][0])->toBe(32);

        $available = array_keys(tensors($ensemble, 'input'));
        $steps = $ensemble['ensemble_scheduling'][0]['step'];

        expect($steps)->toHaveCount(2);

        foreach ($steps as $step) {
            $model = modelConfig($step['model_name'][0]);

            expect($step['model_version'][0])->toBe(-1);

            $modelInputs = array_keys(tensors($model, 'input'));
            $modelOutputs = array_keys(tensors($model, 'output'));

            $mappedInputs = array_map(fn (array $map) => $map['key'][0], $step['input_map']);

            expect($mappedInputs)->toEqualCanonicalizing($modelInputs);                  // a step must supply every input of its model (the printed figure left attention_mask out)

            foreach ($step['input_map'] as $map) {
                expect($available)->toContain($map['value'][0]);                         // fed from an ensemble input or an earlier step's output
            }

            foreach ($step['output_map'] as $map) {
                expect($modelOutputs)->toContain($map['key'][0]);

                $available[] = $map['value'][0];
            }
        }

        foreach (array_keys(tensors($ensemble, 'output')) as $output) {
            expect($available)->toContain($output);                                       // every declared ensemble output is produced by a step
        }

        expect(tensors($ensemble, 'output'))->toBe(['question_vec' => ['type' => 'TYPE_FP32', 'dims' => [768]], 'rerank_scores' => ['type' => 'TYPE_FP32', 'dims' => [1]]]);
    });

    it('keeps every model file out of git and ships none', function () {
        expect(file_get_contents(base_path('.gitignore')))->toContain('/ops/triton/models/*/1/*.onnx')->toContain('/ops/triton/engines/');
        expect(glob(base_path('ops/triton/models/*/1/*.onnx')))->toBe([]);
    });
});

describe('the pbtxt reader the tests rely on', function () {
    it('reads repeated fields, lists, nested messages and comments', function () {
        $parsed = parsePbtxt("name: \"x\" # a comment\nstep [ { a { key: \"k\" value: \"v\" } a { key: \"k2\" value: \"v2\" } } ]\ndims: [ -1, 20 ]\nflag: true\n");

        expect($parsed['name'])->toBe(['x'])
            ->and($parsed['step'][0]['a'])->toHaveCount(2)
            ->and($parsed['step'][0]['a'][1]['value'])->toBe(['v2'])
            ->and($parsed['dims'])->toBe([-1, 20])
            ->and($parsed['flag'])->toBe([true]);
    });
});

describe('ops/compose/triton.yml', function () {
    function tritonCompose(): array
    {
        return Yaml::parseFile(base_path('ops/compose/triton.yml'));
    }

    it('pins the image tags and never says latest', function () {
        $compose = tritonCompose();

        expect($compose['services']['triton']['image'])->toBe('${TRITON_IMAGE:-nvcr.io/nvidia/tritonserver:26.09-vllm-python-py3}')
            ->and($compose['services']['triton-proxy']['image'])->toBe('${TRITON_PROXY_IMAGE:-nginx:1.31.7-alpine}')
            ->and(envExampleValue('TRITON_IMAGE'))->toMatch('#^nvcr\.io/nvidia/tritonserver:\d\d\.\d\d-vllm-python-py3$#')
            ->and(envExampleValue('TRT_IMAGE_TAG'))->toMatch('/^\d\d\.\d\d-trtllm-python-py3$/')
            ->and(file_get_contents(base_path('ops/compose/triton.yml')))->not->toContain(':latest');
    });

    it('publishes only the authenticating proxy, on loopback, and Triton itself publishes nothing', function () {
        $services = tritonCompose()['services'];

        expect($services['triton'])->not->toHaveKey('ports')
            ->and($services['triton-proxy']['ports'])->toBe(['127.0.0.1:${TRITON_PORT:-9000}:9000'])
            ->and($services['triton']['networks']['internal']['aliases'])->toBe(['triton'])
            ->and($services['triton']['profiles'])->toBe(['triton'])
            ->and($services['triton-proxy']['profiles'])->toBe(['triton']);
    });

    it('refuses to start the proxy without a key, and commits none', function () {
        $proxy = tritonCompose()['services']['triton-proxy'];

        expect($proxy['environment']['TRITON_API_KEY'])->toStartWith('${TRITON_API_KEY:?')
            ->and(envExampleValue('TRITON_API_KEY'))->toBe('')
            ->and(file_get_contents(base_path('ops/compose/triton.yml')))->not->toMatch('/TRITON_API_KEY:-/')
            ->and($proxy['depends_on']['triton']['condition'])->toBe('service_healthy');
    });

    it('mounts the repository read-only and checks that the chat model is ready, with a start period for the load', function () {
        $triton = tritonCompose()['services']['triton'];

        expect($triton['volumes'])->toContain('${TRITON_MODELS:-../triton/models}:/models:ro')
            ->and($triton['healthcheck']['test'][1])->toContain('/v2/health/ready')->toContain('/v2/models/chat/ready')
            ->and($triton['healthcheck']['start_period'])->toBe('600s')
            ->and($triton['deploy']['resources']['reservations']['devices'][0])->toBe(['driver' => 'nvidia', 'count' => 1, 'capabilities' => ['gpu']])
            ->and($triton['environment'])->toBe(['HF_TOKEN']);
    });

    it('is not in the root compose.yaml, which would stop Chapter 0 for everyone without a GPU', function () {
        expect(Yaml::parseFile(base_path('compose.yaml'))['include'][0]['path'])->not->toContain('ops/compose/triton.yml');
    });

    it('is valid Compose', function () {
        if (Process::run('docker compose version')->failed()) {
            $this->markTestSkipped('docker compose is not available');
        }

        $result = Process::env(['TRITON_API_KEY' => 'k', 'HF_TOKEN' => ''])->run(['docker', 'compose', '--env-file', base_path('.env.example'), '-f', base_path('ops/compose/base.yml'), '-f', base_path('ops/compose/triton.yml'), '--profile', 'triton', 'config', '--quiet']);

        expect($result->successful())->toBeTrue($result->errorOutput());
    });
});

describe('the nginx proxy in front of Triton', function () {
    it('checks the bearer key, refuses model control, and keeps the key out of the file', function () {
        $conf = file_get_contents(base_path('ops/triton/proxy/nginx.conf.template'));

        expect($conf)->toContain('"Bearer ${TRITON_API_KEY}" 1;')
            ->and($conf)->toContain('location ^~ /v2/repository/ {')
            ->and($conf)->toContain('return 403;')
            ->and($conf)->toContain('if ($triton_authorised = 0) {')
            ->and($conf)->toContain('proxy_pass http://triton:9000;')
            ->and($conf)->toContain('proxy_pass http://triton:8002/metrics;')
            ->and($conf)->not->toMatch('/Bearer [0-9a-f]{16,}/');
    });
});

describe('ops/triton scripts', function () {
    beforeEach(function () {
        if (Process::run('command -v bash')->failed()) {
            $this->markTestSkipped('bash is not available');
        }
    });

    it('parses build-trtllm.sh, pins its image, and builds with the flags of Figure 25-3', function () {
        $script = file_get_contents(base_path('ops/triton/build-trtllm.sh'));

        expect(Process::run(['bash', '-n', base_path('ops/triton/build-trtllm.sh')])->successful())->toBeTrue()
            ->and(is_executable(base_path('ops/triton/build-trtllm.sh')))->toBeTrue()
            ->and($script)->toContain('TRT_IMAGE_TAG="${TRT_IMAGE_TAG:-'.envExampleValue('TRT_IMAGE_TAG').'}"')
            ->and($script)->not->toContain(':latest')
            ->and($script)->toContain('--qformat fp8')
            ->and($script)->toContain('--max_batch_size 64 --max_input_len 8192 --max_seq_len 8704')
            ->and($script)->toContain('--paged_kv_cache enable --use_paged_context_fmha enable --gemm_plugin auto');
    });

    it('stops at the GPU check on a machine with no NVIDIA GPU', function () {
        if (Process::run('command -v nvidia-smi')->successful()) {
            $this->markTestSkipped('this machine has an NVIDIA GPU');
        }

        $result = Process::path(base_path())->run(['bash', base_path('ops/triton/build-trtllm.sh'), '--check']);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toMatch('/nvidia-smi not found|docker not found/');
    });

    it('passes shellcheck when shellcheck is installed', function () {
        if (Process::run('command -v shellcheck')->failed()) {
            $this->markTestSkipped('shellcheck is not installed');
        }

        $result = Process::run(['shellcheck', base_path('ops/triton/build-trtllm.sh')]);

        expect($result->successful())->toBeTrue($result->output());
    });

    it('has an export script that parses and pins every model it downloads to a commit', function () {
        $script = file_get_contents(base_path('ops/triton/export_onnx.py'));

        preg_match_all('/\("([^"]+)", "([0-9a-f]{40})"\)/', $script, $pins, PREG_SET_ORDER);

        expect(array_column($pins, 1))->toBe(['BAAI/bge-reranker-base', 'nomic-ai/nomic-embed-text-v1.5', 'nomic-ai/nomic-bert-2048']);

        foreach (explode("\n", file_get_contents(base_path('ops/triton/requirements-export.txt'))) as $line) {
            if ($line !== '' && ! str_starts_with($line, '#')) {
                expect($line)->toMatch('/^[a-z0-9-]+==\d+\.\d+\.\d+$/');
            }
        }
    });
});
