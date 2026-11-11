<?php

namespace App\Ai\Sandbox;

final readonly class SandboxResult
{
    public function __construct(
        public array $output,
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
