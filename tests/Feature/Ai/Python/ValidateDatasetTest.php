<?php

use Illuminate\Support\Facades\Process;

it('runs the Python validator as an array command in training/ with a timeout, and passes on its report', function () {
    Process::fake(['*validate_dataset.py*' => Process::result(output: json_encode(['ok' => true, 'checks' => []]))]);

    $this->artisan('dataset:validate', ['path' => 'data/sample.jsonl'])->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('scripts/validate_dataset.py', $process->command));
    Process::assertRan(fn ($process) => $process->command === ['uv', 'run', 'python', 'scripts/validate_dataset.py', '--json', 'data/sample.jsonl']
        && $process->path === base_path('training')
        && $process->timeout === 600
        && isset($process->environment['REQUEST_ID']));
});

it('prints the checks and fails when one of them did not pass', function () {
    Process::fake(['*validate_dataset.py*' => Process::result(output: json_encode([
        'ok' => false,
        'checks' => [
            ['check' => 'file has rows', 'passed' => true, 'detail' => '3 rows'],
            ['check' => 'no duplicate rows', 'passed' => false, 'detail' => '2 duplicates'],
        ],
    ]))]);

    $this->artisan('dataset:validate', ['path' => 'data/sample.jsonl'])
        ->expectsOutputToContain('file has rows')
        ->expectsOutputToContain('2 duplicates')
        ->assertFailed();
});

it('treats a non-zero exit code as a failure even when stdout looks fine', function () {
    Process::fake(['*validate_dataset.py*' => Process::result(output: json_encode(['ok' => true, 'checks' => []]), errorOutput: 'Traceback', exitCode: 1)]);

    $this->artisan('dataset:validate', ['path' => 'data/sample.jsonl'])
        ->expectsOutputToContain('validation failed (1)')
        ->assertFailed();
});

it('keeps a path with a space or a semicolon as one argument', function () {
    Process::fake(['*' => Process::result(output: json_encode(['ok' => true, 'checks' => []]))]);

    $this->artisan('dataset:validate', ['path' => 'data/my file; rm -rf.jsonl'])->assertSuccessful();

    Process::assertRan(fn ($process) => end($process->command) === 'data/my file; rm -rf.jsonl');
});
