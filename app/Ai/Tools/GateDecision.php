<?php

namespace App\Ai\Tools;

final class GateDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $requiresApproval = false,
        public readonly bool $audit = false,
        public readonly string $reason = '',
        public readonly string $summary = '',
    ) {}

    public static function allow(bool $audit = false): self
    {
        return new self(allowed: true, audit: $audit);
    }

    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }

    public static function requireApproval(string $summary): self
    {
        return new self(allowed: false, requiresApproval: true, summary: $summary);
    }
}
