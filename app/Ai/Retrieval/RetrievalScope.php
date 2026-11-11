<?php

namespace App\Ai\Retrieval;

use App\Models\User;

final readonly class RetrievalScope
{
    public function __construct(
        public ?int $tenantId = null,
        public ?string $plan = null,
        public ?string $area = null,
    ) {}

    public static function shared(): self
    {
        return new self;
    }

    public static function forUser(User $user, ?string $area = null): self
    {
        return new self(
            tenantId: $user->tenant_id,
            plan: $user->customer?->plan,
            area: $area,
        );
    }
}
