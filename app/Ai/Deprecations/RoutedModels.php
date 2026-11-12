<?php

namespace App\Ai\Deprecations;

// Chapter 50, Topic 4: "fail CI if the retired identifier is still routed". A model is routed when a provider that a tier, a feature or the route table sends traffic to names it.
final class RoutedModels
{
    /** @return array<string, list<string>> model name or API path => the provider names that route to it */
    public function all(): array
    {
        $routed = [];

        foreach ($this->routedProviders() as $provider) {
            $config = $this->providerConfig($provider);

            foreach ([$config['model'] ?? null, $this->apiPath($config)] as $identifier) {
                if (blank($identifier)) {
                    continue;
                }

                $routed[(string) $identifier][] = $provider;
            }
        }

        return array_map(fn (array $providers) => array_values(array_unique($providers)), $routed);
    }

    /** @return list<string> */
    public function routedProviders(): array
    {
        $names = array_values((array) config('ai.tiers', []));

        foreach ((array) config('ai.features', []) as $feature) {
            array_push($names, (string) ($feature['provider'] ?? ''), (string) ($feature['escalate_to'] ?? ''));
        }

        foreach ((array) config('ai-routes', []) as $category => $routes) {
            if ($category === '_meta') {
                continue;
            }

            foreach ((array) $routes as $route) {
                array_push($names, ...array_map('strval', (array) ($route['chain'] ?? [])));
            }
        }

        return array_values(array_unique(array_filter($names, fn (string $name) => $name !== '' && isset(config('ai.providers')[$name]))));
    }

    private function providerConfig(string $name): array
    {
        $config = config('ai.providers')[$name] ?? [];

        if (is_string($config) && str_starts_with($config, '@')) {
            return $this->providerConfig(substr($config, 1));
        }

        return (array) $config;
    }

    private function apiPath(array $config): ?string
    {
        $url = (string) ($config['base_url'] ?? '');

        $host = parse_url($url, PHP_URL_HOST);

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($host)) {
            return null;
        }

        return $host.rtrim((string) $path, '/');
    }
}
