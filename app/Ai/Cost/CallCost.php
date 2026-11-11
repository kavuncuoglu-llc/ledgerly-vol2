<?php

namespace App\Ai\Cost;

use App\Models\ModelCall;

// Chapter 35, Figure 35-9: a pure function of a call's logged usage and a price entry, so the cost of any call can be recomputed later from the same row.
// The repository's columns are prompt_tokens and completion_tokens (the book writes input_tokens and output_tokens); a null cached count is zero.
final class CallCost
{
    public static function usd(ModelCall $call, array $price): float
    {
        return self::usdOf(
            inputTokens: (int) $call->prompt_tokens,
            outputTokens: (int) $call->completion_tokens,
            price: $price,
            cachedInputTokens: (int) $call->cached_input_tokens,
            cacheWriteTokens: (int) $call->cache_write_tokens,
        );
    }

    public static function usdOf(int $inputTokens, int $outputTokens, array $price, int $cachedInputTokens = 0, int $cacheWriteTokens = 0): float
    {
        $cached = min($cachedInputTokens, $inputTokens);

        $written = min($cacheWriteTokens, max(0, $inputTokens - $cached));

        $uncached = $inputTokens - $cached - $written;

        return ($uncached * $price['input']
            + $cached * ($price['cache_read'] ?? $price['input'])
            + $written * ($price['cache_write'] ?? $price['input'])
            + $outputTokens * $price['output']) / ($price['per'] ?? 1_000_000);
    }

    public static function microUsd(ModelCall $call, array $price): int
    {
        return (int) round(self::usd($call, $price) * 1_000_000);
    }
}
