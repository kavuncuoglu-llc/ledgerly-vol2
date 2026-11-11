<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function python(): ?string
{
    return (new ExecutableFinder)->find('python3');
}

it('has a sidecar whose own tests pass offline with the standard library only', function () {
    $process = new Process([python(), '-I', '-m', 'unittest', 'discover', '-s', 'tests'], base_path('sidecar'));

    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('OK');
})->skip(fn () => python() === null, 'python3 is not installed');

it('builds the sidecar image for a conservative Python, pinned, with no credentials', function () {
    $dockerfile = file_get_contents(base_path('sidecar/Dockerfile'));
    $requirements = file(base_path('sidecar/requirements.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $pins = array_filter($requirements, fn (string $line) => ! str_starts_with($line, '#'));

    expect($dockerfile)->toContain('FROM python:3.12-slim')->toContain('USER sidecar')->not->toContain('API_KEY')
        ->and($pins)->not->toBeEmpty()
        ->and(collect($pins)->every(fn (string $line) => preg_match('/^[A-Za-z0-9_.-]+==\d+(\.\d+)*$/', $line) === 1))->toBeTrue();
});

it('declares the sidecar in Compose under a profile, on a network of its own', function () {
    $compose = file_get_contents(base_path('ops/compose/base.yml'));

    expect($compose)->toContain('sidecar:')->toContain('profiles: ["sidecar"]')->toContain('build: ../../sidecar')->toContain('"127.0.0.1:${SIDECAR_PORT:-8008}:8000"');
});
