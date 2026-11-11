<?php

namespace App\Ai\Cost\Audit;

use Illuminate\Support\Collection;

// Everything one conversation (or job, or lone call) caused: its calls, what they cost, who owns it, and whether it ended well.
final class Unit
{
    /** @param  Collection<int, AuditCall>  $calls */
    public function __construct(
        public readonly string $key,
        public readonly Collection $calls,
        public readonly ?int $conversationId,
        public readonly ?int $tenantId,
        public readonly bool $resolved,
    ) {}

    public function usd(): float
    {
        return $this->calls->sum('usd');
    }

    public function callCount(): int
    {
        return $this->calls->count();
    }

    public function feature(): string
    {
        return $this->calls->contains(fn (AuditCall $call) => $call->feature === 'support.reply')
            ? 'support.reply'
            : (string) $this->calls->first()?->feature;
    }

    public function promptVersion(): string
    {
        return (string) ($this->calls->pluck('promptVersion')->filter()->last() ?? 'none');
    }

    public function route(): string
    {
        return (string) $this->calls->sortByDesc('usd')->first()?->provider;
    }

    public function costliest(): ?AuditCall
    {
        return $this->calls->sortByDesc('usd')->first();
    }
}
