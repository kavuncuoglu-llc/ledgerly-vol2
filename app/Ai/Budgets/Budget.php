<?php

namespace App\Ai\Budgets;

use Carbon\CarbonInterface;

// Chapter 36, Topic 5: a number, a period, a scope, and (in the Ladder) a decision about what happens at the limit. Amounts are integer micro-dollars.
final readonly class Budget
{
    public const TENANT_MONTH = 'tenant.month';

    public const TENANT_DAY = 'tenant.day';

    public const FEATURE_DAY = 'feature.day';

    public const ANONYMOUS_DAY = 'anonymous.day';

    public const APP_HOUR = 'app.hour';

    public function __construct(
        public string $key,
        public string $kind,
        public string $label,
        public int $limitMicros,
        public int $ttlSeconds,
        public ?string $subject = null,
        public int $resetsInSeconds = 0,
        public ?CarbonInterface $startsAt = null,
    ) {}

    public function limits(): array
    {
        return ['limit' => $this->limitMicros, 'ttl' => $this->ttlSeconds];
    }
}
