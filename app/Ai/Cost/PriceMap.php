<?php

namespace App\Ai\Cost;

// Chapter 35, Topic 9: the dated table of per-model prices (config/ai-pricing.php). A model is priced by its own entry first, then by the provider rule for your own hardware; nothing else is priced.
final class PriceMap
{
    public function find(string $provider, string $model): ?array
    {
        $models = config('ai-pricing.models', []);

        return $models[$model] ?? config('ai-pricing.providers', [])[$provider] ?? null;     // model names contain dots ("llama3.1:8b"): array keys, not config paths
    }

    public function for(string $provider, string $model): array
    {
        return $this->find($provider, $model) ?? throw UnpricedModel::for($provider, $model);
    }

    public function version(): string
    {
        return (string) config('ai-pricing.version', 'unversioned');
    }

    public function checkedOn(): string
    {
        return (string) config('ai-pricing.checked_on', '');
    }
}
