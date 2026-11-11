<?php

namespace App\Ai\Routing;

final readonly class CascadeOutcome
{
    /** @param  array<int, array{provider: string, failed: string[]}>  $steps  every attempt in order, with the checks it failed */
    public function __construct(
        public AttemptResult $final,
        public bool $handedOff,
        public array $steps,
    ) {}

    public function escalated(): bool
    {
        return count($this->steps) > 1;
    }
}
