<?php

namespace App\Ai\Hardware;

use Illuminate\Support\Facades\Process;

// Everything the sizing and profiling code asks of the machine it runs on goes through here, so a test can describe a Mac, a Strix Halo box or a GPU server without being one.
class HostProbe
{
    public function os(): string
    {
        return PHP_OS_FAMILY;
    }

    public function run(string $command): string
    {
        $result = Process::timeout(10)->run($command);

        if ($result->failed()) {
            return '';
        }

        return trim($result->output());
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return '';
        }

        return trim($contents);
    }

    /** @return list<string> */
    public function glob(string $pattern): array
    {
        return glob($pattern) ?: [];
    }
}
