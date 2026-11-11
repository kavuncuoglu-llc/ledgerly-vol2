<?php

namespace App\Ai\Profile;

use App\Ai\Hardware\HostProbe;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

// PHP cannot tick while it waits on the model, so a small shell loop in a child process writes one block a second and the command reads them back afterwards.
// macOS: ps for the server's resident memory, ioreg for what Metal has mapped (powermetrics would need sudo). Linux: ps, then nvidia-smi or the amdgpu sysfs counters.
final class ShellMemorySampler implements MemorySampler
{
    private ?InvokedProcess $process = null;

    /** @param list<string> $processNames */
    public function __construct(
        private HostProbe $host,
        private array $processNames = ['ollama', 'llama-server'],
        private int $interval = 1,
    ) {
        foreach ($processNames as $name) {
            if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
                throw new InvalidArgumentException("'{$name}' is not a process name.");
            }
        }
    }

    public function gpuKind(): string
    {
        if ($this->host->os() === 'Darwin') {
            return 'ioreg';
        }

        if ($this->host->run('nvidia-smi -L') !== '') {
            return 'nvidia';
        }

        if ($this->host->glob('/sys/class/drm/card*/device/mem_info_vram_used') !== []) {
            return 'sysfs';
        }

        return 'none';
    }

    public function script(): string
    {
        return sprintf(
            'while :; do echo "@@sample $(date +%%s)"; echo "@@ps"; ps -axo rss=,comm= | grep -E "%s"; echo "@@gpu"; %s; sleep %d; done',
            implode('|', $this->processNames),
            $this->gpuCommand($this->gpuKind()),
            max(1, $this->interval),
        );
    }

    public function start(): void
    {
        $this->process = Process::start(['sh', '-c', $this->script()]);
    }

    public function stop(): array
    {
        if ($this->process === null) {
            return [];
        }

        $output = $this->process->output();

        $this->process->signal(15);                                                // SIGTERM; the constant exists only with pcntl
        $this->process = null;

        return SampleParser::parse($output, $this->gpuKind(), $this->processNames);
    }

    private function gpuCommand(string $kind): string
    {
        return match ($kind) {
            'ioreg' => 'ioreg -r -d 1 -w 0 -c IOAccelerator | grep -o \'"In use system memory"=[0-9]*\'',
            'nvidia' => 'nvidia-smi --query-gpu=memory.used --format=csv,noheader,nounits',
            'sysfs' => 'cat /sys/class/drm/card*/device/mem_info_vram_used /sys/class/drm/card*/device/mem_info_gtt_used 2>/dev/null',
            default => 'true',
        };
    }
}
