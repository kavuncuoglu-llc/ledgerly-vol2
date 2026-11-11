<?php

namespace App\Ai\Distillation;

final readonly class Verdict
{
    private function __construct(public bool $kept, public ?string $rejectedBy, public string $reason) {}

    public static function kept(): self
    {
        return new self(true, null, '');
    }

    public static function gates(string $reason): self
    {
        return new self(false, 'gates', $reason);
    }

    public static function judge(string $reason): self
    {
        return new self(false, 'judge', $reason);
    }
}
