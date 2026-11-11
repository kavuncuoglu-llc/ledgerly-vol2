<?php

namespace App\Ai\Compare;

use App\Ai\Contracts\Usage;

// Chapter 20, Topic 8. A hosted provider is paid per token (config('ai.rates'), dollars per million, the table Chapter 2 started). Your own hardware is paid by the hour: a request costs the share of
// the GPU-hour it occupied, which is its wall time divided by how many requests the server runs at once. Both inputs are config; the numbers shipped there are examples, not quotes.
final class CostModel
{
    /** @return float dollars for one request */
    public function of(string $provider, string $model, Usage $usage, int $latencyMs): float
    {
        $perToken = $this->tokenCost($provider, $model, $usage);

        if ($perToken > 0) {
            return $perToken;
        }

        return $this->gpuCost($provider, $latencyMs);
    }

    public function isMeasuredByGpuHour(string $provider): bool
    {
        return $this->usdPerHour($provider) > 0 && ! $this->hasTokenRates($provider);
    }

    private function tokenCost(string $provider, string $model, Usage $usage): float
    {
        $rates = $this->ratesFor($provider, $model);

        return ($usage->promptTokens * $rates['in'] + $usage->completionTokens * $rates['out']) / 1_000_000;
    }

    private function gpuCost(string $provider, int $latencyMs): float
    {
        $concurrency = max(1, (int) (config('ai.pricing.gpu')[$provider]['concurrency'] ?? 1));

        return $this->usdPerHour($provider) * ($latencyMs / 3_600_000) / $concurrency;
    }

    private function usdPerHour(string $provider): float
    {
        return (float) (config('ai.pricing.gpu')[$provider]['usd_per_hour'] ?? 0);
    }

    private function hasTokenRates(string $provider): bool
    {
        $rates = config('ai.rates')[$provider]['*'] ?? null;

        return $rates !== null && ($rates['in'] > 0 || $rates['out'] > 0);
    }

    private function ratesFor(string $provider, string $model): array
    {
        $providerRates = config('ai.rates')[$provider] ?? [];

        return $providerRates[$model] ?? $providerRates['*'] ?? ['in' => 0, 'out' => 0];
    }
}
