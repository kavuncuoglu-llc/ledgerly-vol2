<?php

use App\Ai\Sandbox\SandboxFailed;
use App\Ai\Sandbox\SandboxRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Chapter 33, Topic 7: the command line is the policy, so it is pinned. The container itself was run for real; see the HANDOFF notes.
function sandbox(array $overrides = []): SandboxRunner
{
    return new SandboxRunner($overrides + ['enabled' => true, 'directory' => sys_get_temp_dir().'/sandbox-'.uniqid(), 'image' => 'ledgerly-sandbox:test']);
}

it('is off unless a worker host turns it on', function () {
    (new SandboxRunner(['enabled' => false]))->run('print(1)');
})->throws(SandboxFailed::class, 'The sandbox is off');

it('builds the restricted command from the chapter', function () {
    $command = sandbox()->command('/tmp/task');

    expect(implode(' ', $command))->toBe('docker run --rm --network none --read-only --tmpfs /tmp:size=64m --user 65534:65534 --cap-drop ALL --security-opt no-new-privileges --memory 256m --cpus 0.5 --pids-limit 64 -v /tmp/task/in:/in:ro -v /tmp/task/out:/out ledgerly-sandbox:test timeout 10 python /in/script.py');
});

it('adds gVisor when a runtime is configured', function () {
    expect(implode(' ', sandbox(['runtime' => 'runsc'])->command('/t')))->toContain('--runtime runsc');
});

it('copies files into /in, reads the answer from /out and deletes the task directory', function () {
    $directory = sys_get_temp_dir().'/sandbox-'.uniqid();

    Process::fake(function (PendingProcess $process) use ($directory) {
        $task = collect(glob("{$directory}/*"))->first();

        expect(File::get("{$task}/in/script.py"))->toBe('print(1)')
            ->and(File::get("{$task}/in/invoice.json"))->toBe('{"total": 10}');

        File::put("{$task}/out/result.json", '{"prorated": 4}');

        return Process::result(output: 'hello', exitCode: 0);
    });

    $result = sandbox(['directory' => $directory])->run('print(1)', ['../../invoice.json' => '{"total": 10}']);

    expect($result->output)->toBe(['prorated' => 4])
        ->and(trim($result->stdout))->toBe('hello')
        ->and($result->succeeded())->toBeTrue()
        ->and(glob("{$directory}/*"))->toBe([]);
});

it('reports a timeout without trusting any output', function () {
    Process::fake(fn () => Process::result(exitCode: 124));

    $result = sandbox()->run('while True: pass');

    expect($result->timedOut)->toBeTrue()
        ->and($result->succeeded())->toBeFalse()
        ->and($result->output)->toBe([]);
});

it('refuses a result that is not JSON, too large, or missing', function (?string $contents) {
    Process::fake(function () use ($contents) {
        $task = collect(glob(sys_get_temp_dir().'/sandbox-*/*'))->sortByDesc(fn ($path) => filemtime($path))->first();

        if ($contents !== null) {
            File::put("{$task}/out/result.json", $contents);
        }

        return Process::result(exitCode: 0);
    });

    sandbox(['max_output_bytes' => 100])->run('x');
})->with([
    'missing' => [null],
    'not json' => ['not json'],
    'too large' => [fn () => str_repeat('a', 200)],
])->throws(SandboxFailed::class);

it('refuses a result that is a symlink to a file outside the output directory', function () {
    $secret = tempnam(sys_get_temp_dir(), 'secret');

    file_put_contents($secret, '{"stolen": true}');

    Process::fake(function () use ($secret) {
        $task = collect(glob(sys_get_temp_dir().'/sandbox-*/*'))->sortByDesc(fn ($path) => filemtime($path))->first();

        symlink($secret, "{$task}/out/result.json");

        return Process::result(exitCode: 0);
    });

    try {
        sandbox()->run('x');
    } finally {
        unlink($secret);
    }
})->throws(SandboxFailed::class, 'plain file');

it('never makes the output directory world-writable', function () {
    $modes = [];

    Process::fake(function () use (&$modes) {
        $task = collect(glob(sys_get_temp_dir().'/sandbox-*/*'))->sortByDesc(fn ($path) => filemtime($path))->first();

        $modes[] = fileperms("{$task}/out") & 0777;

        File::put("{$task}/out/result.json", '{}');

        return Process::result(exitCode: 0);
    });

    sandbox()->run('x');

    expect($modes[0] & 0007)->toBe(0);
});
