<?php

namespace App\Evals;

final readonly class GateResult
{
    public const QUALITY = 'quality';

    public const NEVER = 'never';

    public function __construct(
        public string $gate,
        public string $kind,
        public bool $passed,
        public string $reason = 'ok',
    ) {}

    public function toArray(): array
    {
        return ['gate' => $this->gate, 'kind' => $this->kind, 'passed' => $this->passed, 'reason' => $this->reason];
    }
}
