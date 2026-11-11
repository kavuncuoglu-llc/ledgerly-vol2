<?php

use Symfony\Component\Yaml\Yaml;

function pyBase(): array
{
    return Yaml::parseFile(base_path('ops/compose/base.yml'))['services'];
}

it('runs the embedding worker with Redis and no database credential', function () {
    $worker = pyBase()['py-worker'];

    expect($worker['profiles'])->toBe(['python'])
        ->and($worker['command'])->toBe(['uv', 'run', 'python', '-m', 'workers.embed_worker'])
        ->and($worker['environment']['REDIS_URL'])->toBe('redis://redis:6379/0')
        ->and(array_keys($worker['environment']))->each->not->toStartWith('DB_')
        ->and($worker)->not->toHaveKey('env_file')
        ->and($worker['depends_on']['redis']['condition'])->toBe('service_healthy')
        ->and($worker['restart'])->toBe('unless-stopped')
        ->and($worker['healthcheck'])->not->toBeEmpty()
        ->and($worker['image'])->toBe('ledgerly-training:${TRAINING_TAG:-vol2}');
});

it('pins both Hugging Face repositories whose code the worker runs', function () {
    $worker = pyBase()['py-worker'];

    expect(envExampleValue('NOMIC_REVISION'))->toMatch('/^[0-9a-f]{40}$/')
        ->and(envExampleValue('NOMIC_CODE_REVISION'))->toMatch('/^[0-9a-f]{40}$/')
        ->and($worker['environment']['NOMIC_REVISION'])->toContain(envExampleValue('NOMIC_REVISION'))
        ->and($worker['environment']['NOMIC_CODE_REVISION'])->toContain(envExampleValue('NOMIC_CODE_REVISION'));
});

it('runs py:listen from the app image and lets it reach Postgres and Redis', function () {
    $listen = pyBase()['py-listen'];

    expect($listen['profiles'])->toBe(['python'])
        ->and($listen['command'])->toBe(['php', 'artisan', 'py:listen', '--stream=py:results'])
        ->and($listen['build']['dockerfile'])->toBe('ops/app/Dockerfile')
        ->and($listen['depends_on'])->toHaveKeys(['postgres', 'redis'])
        ->and($listen['environment']['DB_HOST'])->toBe('postgres')
        ->and($listen['restart'])->toBe('unless-stopped');
});

it('keeps the training image slim, pinned and separate from the app image', function () {
    $dockerfile = file_get_contents(base_path('training/Dockerfile'));
    $appIgnore = file_get_contents(base_path('.dockerignore'));

    expect($dockerfile)->toContain('FROM python:3.12.13-slim-bookworm')
        ->and($dockerfile)->toContain('uv==0.11.6')
        ->and($dockerfile)->toContain('uv sync --locked --no-dev')
        ->and($dockerfile)->not->toContain(':latest')
        ->and(explode("\n", $appIgnore))->toContain('training');
});

it('adds the GPU reservation for the worker in its own file', function () {
    $override = Yaml::parseFile(base_path('ops/compose/python.gpu.yml'))['services']['py-worker'];

    expect($override['build']['args']['UV_SYNC_ARGS'])->toBe('--extra cuda')
        ->and($override['deploy']['resources']['reservations']['devices'][0]['driver'])->toBe('nvidia');
});

it('runs MLflow on loopback only, with a pinned image and the book\'s server flags', function () {
    $mlflow = Yaml::parseFile(base_path('ops/compose/mlflow.yml'))['services']['mlflow'];
    $command = implode(' ', $mlflow['command']);

    expect($mlflow['profiles'])->toBe(['mlflow'])
        ->and($mlflow['ports'])->toBe(['127.0.0.1:${MLFLOW_PORT:-5050}:5000'])
        ->and($mlflow['image'])->toBe('${MLFLOW_IMAGE:-ghcr.io/mlflow/mlflow:v3.17.2}')
        ->and(envExampleValue('MLFLOW_IMAGE'))->toMatch('#^ghcr\.io/mlflow/mlflow:v\d+\.\d+\.\d+$#')
        ->and($command)->toContain('--backend-store-uri sqlite:////mlflow/mlflow.db')
        ->and($command)->toContain('--artifacts-destination /mlartifacts')
        ->and($mlflow['volumes'])->toBe(['mlflow-db:/mlflow', 'mlflow-artifacts:/mlartifacts'])
        ->and($mlflow['networks'])->toBe(['internal'])
        ->and(envExampleValue('MLFLOW_TRACKING_URI'))->toBe('http://localhost:5050')
        ->and(envExampleValue('MLFLOW_PORT'))->toBe('5050');
});

it('defines the CI jobs of Figure 27-6, none of which needs a secret', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $source = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect(array_keys($workflow['jobs']))->toBe(['php', 'python', 'contracts'])
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($source)->not->toContain('secrets.')
        ->and($source)->toContain('uv sync --locked')
        ->and($source)->toContain('--filter=ContractFixtures')
        ->and($source)->toContain('git diff --exit-code -- training/contracts');
});

it('keeps the training lockfile and Python version in the repository', function () {
    expect(trim(file_get_contents(base_path('training/.python-version'))))->toBe('3.12')
        ->and(file_exists(base_path('training/uv.lock')))->toBeTrue()
        ->and(file_get_contents(base_path('training/pyproject.toml')))->toContain('cuda = ')->toContain('rocm = ')->toContain('mlx = ');
});
