<?php

namespace App\Tenancy;

use Closure;

final class CurrentTenant
{
    private bool $explicit = false;

    private ?int $tenantId = null;

    public function run(?int $tenantId, Closure $callback): mixed   // null = no tenant filter at all: console jobs, retention, user:forget
    {
        $previous = [$this->explicit, $this->tenantId];

        $this->explicit = true;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            [$this->explicit, $this->tenantId] = $previous;
        }
    }

    public function set(?int $tenantId): void
    {
        $this->explicit = true;
        $this->tenantId = $tenantId;
    }

    public function forget(): void
    {
        $this->explicit = false;
        $this->tenantId = null;
    }

    /** @return int|null|false an id to filter by, null when nobody may see anything, false when there is no filter */
    public function resolve(): int|false|null
    {
        if ($this->explicit) {
            return $this->tenantId ?? false;
        }

        $user = auth()->user();

        if ($user !== null) {
            return $user->tenant_id;
        }

        return app()->runningInConsole()
            ? false
            : null;   // a web request with nobody signed in sees nothing
    }

    public function id(): ?int
    {
        $resolved = $this->resolve();

        return $resolved === false
            ? null
            : $resolved;
    }
}
