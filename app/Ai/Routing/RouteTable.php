<?php

namespace App\Ai\Routing;

use App\Ai\AiManager;
use App\Ai\Schemas\Category;

// Chapter 37, Topic 2: config/ai-routes.php, written by eval:route-table from golden-set pass rates and committed, so a routing change is a reviewed diff. A category with no row, or no role that is set up and allowed, has no plan:
// the support assistant then answers as it always has, on the feature's own role.
final class RouteTable
{
    public function __construct(private AiManager $ai) {}

    public function enabled(): bool
    {
        return (bool) config('ai.cascade.enabled') && config('ai-routes', []) !== [];
    }

    public function for(?Category $category, string $feature = 'support.reply'): ?RoutePlan
    {
        $key = $this->key($category);

        $row = config('ai-routes')[$key]['default'] ?? config('ai-routes')['default']['default'] ?? null;

        if ($row === null) {
            return null;
        }

        $chain = $this->usable(array_map(fn (string $role) => Roles::provider($role), (array) ($row['chain'] ?? [])), $feature);

        if ($chain === []) {
            return null;
        }

        return new RoutePlan(
            category: $key,
            chain: $chain,
            buffers: (bool) ($row['buffers'] ?? count($chain) > 1),
            checksRetrieval: in_array($key, (array) config('ai.cascade.checks_retrieval', ['howto', 'policy']), true),
            declineBelow: config('ai.cascade.decline_below'),
            skipCheapestBelow: config('ai.cascade.skip_cheapest_below'),
        );
    }

    // The category a router label is filed under in the table (the golden set's names: billing, howto, account, bug, policy, safety).
    public function key(?Category $category): string
    {
        if ($category === null) {
            return 'default';
        }

        return (string) (config('ai.cascade.category_map')[$category->value] ?? $category->value);
    }

    /** @return string[] only the providers that are set up and that the feature may send its data to, in order, once each */
    private function usable(array $providers, string $feature): array
    {
        $usable = [];

        foreach ($providers as $provider) {
            if (in_array($provider, $usable, true)) {
                continue;
            }

            if (! $this->ai->configured($provider)) {
                continue;
            }

            if (! $this->ai->mayReceive($feature, $provider)) {
                continue;                                                          // nothing leaves the machine because a route names it
            }

            $usable[] = $provider;
        }

        return $usable;
    }
}
