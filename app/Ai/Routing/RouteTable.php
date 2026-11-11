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

    // Chapter 44, Topic 5: a tenant with a data residency gets the category's `residency` route (a role list of its own, usually shorter), and any role in it whose recorded region is not the tenant's is refused, whatever the table says (Chapter 34, Exercise 5).
    public function for(?Category $category, string $feature = 'support.reply', ?string $residency = null): ?RoutePlan
    {
        $key = $this->key($category);

        $entry = config('ai-routes')[$key] ?? config('ai-routes')['default'] ?? null;

        $row = $residency === null
            ? ($entry['default'] ?? null)
            : ($entry['residency'] ?? $entry['default'] ?? null);

        if ($row === null) {
            return null;
        }

        $chain = $this->usable(array_map(fn (string $role) => Roles::provider($role), (array) ($row['chain'] ?? [])), $feature, $residency);

        if ($chain === [] && $residency === null) {
            return null;
        }

        return new RoutePlan(
            category: $key,
            chain: $chain,
            buffers: (bool) ($row['buffers'] ?? count($chain) > 1),
            checksRetrieval: in_array($key, (array) config('ai.cascade.checks_retrieval', ['howto', 'policy']), true),
            declineBelow: config('ai.cascade.decline_below'),
            skipCheapestBelow: config('ai.cascade.skip_cheapest_below'),
            residency: $residency,
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
    private function usable(array $providers, string $feature, ?string $residency = null): array
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

            if ($residency !== null && ! in_array($this->regionOf($provider), [$residency, 'local'], true)) {
                continue;                                                          // Chapter 44: out of region is out of the chain. `local` is this machine's own hardware, which is where Chapter 34's exercise falls back to
            }

            $usable[] = $provider;
        }

        return $usable;
    }

    private function regionOf(string $provider): ?string
    {
        $config = $this->ai->config($provider);

        return $config['privacy']['region'] ?? $config['region'] ?? null;
    }
}
