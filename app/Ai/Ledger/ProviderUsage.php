<?php

namespace App\Ai\Ledger;

// Chapter 36, Topic 1: what a provider says it billed for a day, in a shape every source is turned into: one row per model.
interface ProviderUsage
{
    /** @return array<string, array{input_tokens: int, output_tokens: int, cost_usd: float}> model => the provider's figures for that day */
    public function forDay(string $provider, string $date): array;
}
