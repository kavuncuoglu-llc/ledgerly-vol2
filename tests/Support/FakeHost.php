<?php

namespace Tests\Support;

use App\Ai\Hardware\HostProbe;

// A machine described in four arrays: its OS, what its commands print, what its files hold, what its globs find.
final class FakeHost extends HostProbe
{
    public function __construct(
        private string $os,
        private array $commands = [],
        private array $files = [],
        private array $globs = [],
    ) {}

    public static function mac(int $gib = 128, int $wiredLimitMb = 0, string $chip = 'Apple M3 Max'): self
    {
        return new self('Darwin', [
            'sysctl -n hw.memsize' => (string) ($gib * 1_073_741_824),
            'sysctl -n iogpu.wired_limit_mb' => (string) $wiredLimitMb,
            'sysctl -n machdep.cpu.brand_string' => $chip,
        ]);
    }

    public function os(): string
    {
        return $this->os;
    }

    public function run(string $command): string
    {
        return $this->commands[$command] ?? '';
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? '';
    }

    public function glob(string $pattern): array
    {
        return $this->globs[$pattern] ?? [];
    }
}
