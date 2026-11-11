<?php

namespace App\Ai\Sandbox;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;

// Chapter 33, Topic 7: one-shot, restricted containers for code a model wrote. Run it on a worker host, never the app host: SANDBOX_ENABLED is off everywhere else.
// The data goes in as files, the answer comes out as one JSON file that is size-checked and parsed as untrusted input, and the task directory is deleted afterwards.
final class SandboxRunner
{
    public const RESULT_FILE = 'result.json';

    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('ai.sandbox'));
    }

    /** @param array<string, string> $files name => contents, copied into /in */
    public function run(string $script, array $files = []): SandboxResult
    {
        if (! ($this->config['enabled'] ?? false)) {
            throw new SandboxFailed('The sandbox is off here. Set SANDBOX_ENABLED=true on a worker host that has Docker.');
        }

        $task = $this->makeTaskDirectory($script, $files);

        try {
            $process = Process::timeout($this->timeout() + 15)->run($this->command($task));

            return $this->resultOf($task, $process->exitCode(), $process->output(), $process->errorOutput());
        } finally {
            File::deleteDirectory($task);
        }
    }

    public function command(string $task): array
    {
        $timeout = $this->timeout();

        $runtime = (string) ($this->config['runtime'] ?? '');

        return array_values(array_filter([
            $this->config['docker'] ?? 'docker', 'run', '--rm',
            '--network', 'none',                                                           // no network at all
            '--read-only', '--tmpfs', '/tmp:size='.($this->config['tmpfs'] ?? '64m'),     // read-only root, a little scratch space
            '--user', '65534:65534',                                                       // nobody
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',                    // no extra kernel powers
            '--memory', (string) ($this->config['memory'] ?? '256m'),
            '--cpus', (string) ($this->config['cpus'] ?? '0.5'),
            '--pids-limit', (string) ($this->config['pids'] ?? 64),
            $runtime === '' ? null : '--runtime',
            $runtime === '' ? null : $runtime,                                             // runsc: gVisor, where installed
            '-v', "{$task}/in:/in:ro", '-v', "{$task}/out:/out",
            (string) ($this->config['image'] ?? 'ledgerly-sandbox:vol2'),
            'timeout', (string) $timeout, 'python', '/in/script.py',
        ], fn ($part) => $part !== null));
    }

    private function makeTaskDirectory(string $script, array $files): string
    {
        $task = rtrim((string) ($this->config['directory'] ?? storage_path('sandbox')), '/').'/'.Str::uuid();

        File::ensureDirectoryExists("{$task}/in", 0755);

        File::ensureDirectoryExists("{$task}/out", 0777);

        chmod("{$task}/out", 0777);                                                        // nobody writes the answer; the app reads it

        File::put("{$task}/in/script.py", $script);

        foreach ($files as $name => $contents) {
            File::put("{$task}/in/".basename($name), $contents);                           // basename: a file name cannot climb out of /in
        }

        return $task;
    }

    private function resultOf(string $task, int $exitCode, string $stdout, string $stderr): SandboxResult
    {
        $timedOut = $exitCode === 124;                                                     // timeout(1)'s code

        return new SandboxResult(
            output: $exitCode === 0 ? $this->readOutput("{$task}/out/".self::RESULT_FILE) : [],
            stdout: Str::limit($stdout, 4000),
            stderr: Str::limit($stderr, 4000),
            exitCode: $exitCode,
            timedOut: $timedOut,
        );
    }

    private function readOutput(string $path): array
    {
        if (! File::exists($path)) {
            throw new SandboxFailed('The script finished without writing '.self::RESULT_FILE.'.');
        }

        if (File::size($path) > (int) ($this->config['max_output_bytes'] ?? 65536)) {
            throw new SandboxFailed('The result file is larger than the limit.');
        }

        try {
            $decoded = json_decode(File::get($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SandboxFailed('The result file is not JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new SandboxFailed('The result file must hold a JSON object or list.');
        }

        return $decoded;                                                                   // still untrusted: the caller validates it against its own schema
    }

    private function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 10);
    }
}
