<?php

namespace App\Ai\Cache;

use App\Ai\Tokens\TokenCounter;
use Illuminate\Support\Facades\Cache;

// A local server bills nothing and reports no cache hit, so estimate it: the part of this prompt that is byte-identical to the previous call's prompt in the same conversation
final class PrefixCacheEstimator
{
    private const MAX_BYTES = 262144;

    private const REMEMBER_SECONDS = 3600;

    public function __construct(private TokenCounter $counter) {}

    public function estimate(int $conversationId, string $feature, array $tools, array $messages): int
    {
        $key = "prompt-prefix:{$conversationId}:{$feature}";

        $current = substr(json_encode([$tools, $messages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, self::MAX_BYTES);

        $previous = Cache::get($key);

        Cache::put($key, $current, self::REMEMBER_SECONDS);

        if (! is_string($previous)) {
            return 0;
        }

        return $this->counter->count(substr($current, 0, $this->sharedPrefixLength($previous, $current)));
    }

    public function sharedPrefixLength(string $a, string $b): int
    {
        $length = min(strlen($a), strlen($b));

        return strspn($a ^ $b, "\0", 0, $length);                        // XOR is zero exactly where the bytes match
    }
}
