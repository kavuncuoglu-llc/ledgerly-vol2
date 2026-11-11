<?php

use Symfony\Component\Yaml\Yaml;

function composeFile(string $name): array
{
    return Yaml::parseFile(base_path("ops/compose/{$name}"));
}

function composeFiles(): array
{
    return glob(base_path('ops/compose/*.yml'));
}

it('keeps Chapter 0 working: the root compose.yaml includes the files in ops/compose', function () {
    $root = Yaml::parseFile(base_path('compose.yaml'));

    expect($root['include'][0]['path'])->toBe(['ops/compose/base.yml', 'ops/compose/ollama.yml'])
        ->and($root['include'][0]['project_directory'])->toBe('ops/compose')
        ->and($root['include'][0]['env_file'])->toBe('.env')
        ->and(file_exists(base_path('ops/compose/base.yml')))->toBeTrue();
});

it('has the same .env.example at the root (Chapter 0) and in ops/compose (Appendix L)', function () {
    expect(file_get_contents(base_path('ops/compose/.env.example')))->toBe(file_get_contents(base_path('.env.example')));
});

it('runs Postgres with pgvector and Redis in the base file, and nothing from a model server', function () {
    $base = composeFile('base.yml');

    expect($base['name'])->toBe('ledgerly')
        ->and($base['services']['postgres']['image'])->toBe('pgvector/pgvector:pg16')
        ->and($base['services']['redis']['image'])->toBe('redis:7-alpine')
        ->and($base['services']['sidecar']['profiles'])->toBe(['sidecar'])
        ->and(array_keys($base['services']))->not->toContain('ollama');
});

it('publishes no port on a public interface, anywhere in ops/compose', function () {
    expect(composeFiles())->not->toBeEmpty();

    foreach (composeFiles() as $file) {
        foreach (Yaml::parseFile($file)['services'] ?? [] as $name => $service) {
            foreach ($service['ports'] ?? [] as $port) {
                expect($port)->toStartWith('127.0.0.1:', basename($file)." service {$name} publishes {$port}");
            }
        }
    }
});

it('puts Ollama on the private network with the server settings from Figure 22-7', function () {
    $ollama = composeFile('ollama.yml')['services']['ollama'];

    expect($ollama['profiles'])->toBe(['ollama'])
        ->and($ollama['networks'])->toBe(['internal'])
        ->and($ollama['image'])->toStartWith('${OLLAMA_IMAGE:-ollama/ollama:')
        ->and($ollama['image'])->not->toContain(':latest')
        ->and($ollama['ports'])->toBe(['127.0.0.1:${OLLAMA_PORT:-11434}:11434'])
        ->and($ollama['volumes'])->toContain('ollama-models:/root/.ollama')->toContain('../ollama:/modelfiles:ro')
        ->and($ollama['environment'])->toBe([
            'OLLAMA_HOST' => '0.0.0.0',
            'OLLAMA_NUM_PARALLEL' => '${OLLAMA_NUM_PARALLEL:-2}',
            'OLLAMA_MAX_LOADED_MODELS' => '${OLLAMA_MAX_LOADED_MODELS:-3}',
            'OLLAMA_KEEP_ALIVE' => '${OLLAMA_KEEP_ALIVE:-30m}',
            'OLLAMA_FLASH_ATTENTION' => '${OLLAMA_FLASH_ATTENTION:-1}',
        ])
        ->and($ollama['healthcheck']['test'])->toBe(['CMD', 'ollama', 'list'])
        ->and($ollama)->not->toHaveKey('deploy');                       // the GPU reservation is ollama.gpu.yml, so the profile starts on a CPU-only machine
});

it('builds ledgerly-support from the Modelfile in an init service that waits for a healthy server', function () {
    $init = composeFile('ollama.yml')['services']['ollama-init'];

    expect($init['depends_on']['ollama']['condition'])->toBe('service_healthy')
        ->and($init['restart'])->toBe('no')
        ->and($init['entrypoint'][2])->toContain('ollama pull nomic-embed-text')
        ->and($init['entrypoint'][2])->toContain('ollama create ledgerly-support -f /modelfiles/Modelfile.ledgerly-support')
        ->and(file_exists(base_path('ops/ollama/Modelfile.ledgerly-support')))->toBeTrue();
});

it('adds the NVIDIA reservation as a separate file and the host-gateway line in the macOS override', function () {
    $gpu = composeFile('ollama.gpu.yml')['services']['ollama']['deploy']['resources']['reservations']['devices'][0];
    $mac = composeFile('macos.override.yml')['services']['sidecar'];

    expect($gpu)->toBe(['driver' => 'nvidia', 'count' => 'all', 'capabilities' => ['gpu']])
        ->and($mac['extra_hosts'])->toBe(['host.docker.internal:host-gateway'])
        ->and($mac['environment']['OLLAMA_URL'])->toBe('http://host.docker.internal:11434/v1');
});

it('pins the Ollama Modelfile to the app budget so ai:doctor passes out of the box', function () {
    $modelfile = parseModelfile(base_path('ops/ollama/Modelfile.ledgerly-support'));

    expect($modelfile['FROM'])->toBe(['llama3.1:8b-instruct-q4_K_M'])
        ->and((int) $modelfile['PARAMETER']['num_ctx'][0])->toBeGreaterThanOrEqual((int) config('ai.budget.window'))
        ->and($modelfile['PARAMETER']['num_predict'])->toBe(['400'])
        ->and($modelfile['PARAMETER']['temperature'])->toBe(['0.7'])
        ->and($modelfile['PARAMETER']['stop'])->toBe(['<|eot_id|>'])
        ->and($modelfile['SYSTEM'])->toBe([])
        ->and($modelfile['LICENSE'][0])->toContain('Llama 3.1 Community License');
});

it('has no key in any compose file or env example', function () {
    foreach ([...composeFiles(), base_path('.env.example'), base_path('ops/compose/.env.example')] as $file) {
        expect(file_get_contents($file))->not->toMatch('/sk-[A-Za-z0-9]{10,}/');
    }
});
