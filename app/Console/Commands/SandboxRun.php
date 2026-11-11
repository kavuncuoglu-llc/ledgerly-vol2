<?php

namespace App\Console\Commands;

use App\Ai\Sandbox\SandboxFailed;
use App\Ai\Sandbox\SandboxRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SandboxRun extends Command
{
    protected $signature = 'sandbox:run
        {script : A Python file to run in the sandbox (/in/script.py); it writes /out/result.json}
        {--file=* : Files to copy into /in, read-only}';

    protected $description = 'Run a script in the restricted sandbox container (Chapter 33, Topic 7)';

    public function handle(SandboxRunner $runner): int
    {
        if (! File::exists($this->argument('script'))) {
            $this->error('No such script: '.$this->argument('script'));

            return self::FAILURE;
        }

        $files = [];

        foreach ((array) $this->option('file') as $path) {
            $files[basename($path)] = File::get($path);
        }

        try {
            $result = $runner->run(File::get($this->argument('script')), $files);
        } catch (SandboxFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('exit '.$result->exitCode.($result->timedOut ? ' (timed out)' : ''));

        if ($result->stdout !== '') {
            $this->line('stdout: '.trim($result->stdout));
        }

        if ($result->stderr !== '') {
            $this->line('stderr: '.trim($result->stderr));
        }

        $this->line('result: '.json_encode($result->output, JSON_UNESCAPED_SLASHES));

        return $result->succeeded()
            ? self::SUCCESS
            : self::FAILURE;
    }
}
