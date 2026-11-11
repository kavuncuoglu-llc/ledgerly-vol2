<?php

namespace App\Ai\Budgets;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Throwable;

// A tenant's plan and time zone, for its monthly budget and for the midnight its daily one resets at. Remembered briefly: a plan change takes effect within ten minutes.
final class TenantProfile
{
    /** @return array{plan: string, timezone: string} */
    public function of(int $tenantId): array
    {
        return Cache::remember("budget-profile:{$tenantId}", 600, function () use ($tenantId) {
            try {
                $customer = Tenant::query()->with('customer')->find($tenantId)?->customer;
            } catch (Throwable) {
                $customer = null;
            }

            return [
                'plan' => (string) ($customer->plan ?? 'default'),
                'timezone' => (string) ($customer->timezone ?? config('app.timezone', 'UTC')),
            ];
        });
    }
}
