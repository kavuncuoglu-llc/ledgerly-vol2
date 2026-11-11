<?php

namespace App\Ai\Ledger;

use App\Ai\Cost\CallCost;
use App\Ai\Cost\PriceMap;

// Chapter 36, Topic 2: the one place a call's cost in micro-dollars is worked out. The reservation settles with it, the ledger row stores it, and ai:reconcile and the audit can recompute it from the same row.
// Your own hardware has no token price; ai.ledger.hardware_usd_per_million_tokens can charge an amortized rate so a team's local use shows up in its spend (0 by default: the saving of moving a feature to local shows as tokens).
final class Costing
{
    public function __construct(private PriceMap $prices) {}

    /** @return array{micros: int, kind: string, reason: ?string} */
    public function of(string $provider, string $model, int $inputTokens, int $outputTokens, int $cachedInputTokens = 0, int $cacheWriteTokens = 0): array
    {
        $price = $this->prices->find($provider, $model);

        if ($price === null) {
            return ['micros' => 0, 'kind' => 'call', 'reason' => 'unpriced model'];
        }

        if ($price['input'] > 0 || $price['output'] > 0) {
            return ['micros' => $this->round(CallCost::usdOf($inputTokens, $outputTokens, $price, $cachedInputTokens, $cacheWriteTokens)), 'kind' => 'call', 'reason' => null];
        }

        $perMillion = $this->hardwareRate($provider);

        if ($perMillion > 0) {
            return ['micros' => $this->round(($inputTokens + $outputTokens) * $perMillion / 1_000_000), 'kind' => 'hardware', 'reason' => 'amortized hardware rate'];
        }

        return ['micros' => 0, 'kind' => 'call', 'reason' => null];
    }

    // The most a call can cost, for the reservation: every input token at the input price plus max_tokens at the output price. An unpriced hosted model is estimated at ai.budgets.unpriced_usd_per_million so it cannot be free by accident.
    public function estimate(string $provider, string $model, int $inputTokens, int $maxTokens): int
    {
        $price = $this->prices->find($provider, $model);

        if ($price === null) {
            return config("ai.providers.{$provider}.hosted")
                ? $this->round(($inputTokens + $maxTokens) * (float) config('ai.budgets.unpriced_usd_per_million', 15.0) / 1_000_000)
                : 0;
        }

        $micros = $this->of($provider, $model, $inputTokens, $maxTokens);

        return $micros['micros'];
    }

    public function version(): string
    {
        return $this->prices->version();
    }

    private function hardwareRate(string $provider): float
    {
        return (float) config("ai.ledger.hardware_usd_per_million_tokens.{$provider}", config('ai.ledger.hardware_usd_per_million_tokens.*', 0));
    }

    private function round(float $usd): int
    {
        return (int) round($usd * 1_000_000);
    }
}
