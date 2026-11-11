<?php

namespace App\Ai\Cost;

// Chapter 35, Topic 9: the dated table of per-model prices (config/ai-pricing.php). A model is priced by its own entry first, then by the provider rule for your own hardware; nothing else is priced.
final class PriceMap
{
    public function find(string $provider, string $model): ?array
    {
        $models = config('ai-pricing.models', []);                                      // model names contain dots ("llama3.1:8b"): array keys, not config paths

        return $models[$model] ?? $this->dated($models, $model) ?? config('ai-pricing.providers', [])[$provider] ?? null;
    }

    // A provider may answer with a dated or tagged variant of the model you named ("gpt-x-2026-11-01" for "gpt-x"): the longest priced name the answer starts with.
    private function dated(array $models, string $model): ?array
    {
        $best = null;

        foreach ($models as $name => $price) {
            if ($name !== '' && strlen((string) $name) > strlen((string) $best) && preg_match('/^'.preg_quote((string) $name, '/').'[-:@]/', $model) === 1) {
                $best = $name;
            }
        }

        return $best === null
            ? null
            : $models[$best];
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
