<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\RetrievedChunk;

final readonly class AssembledPrompt
{
    /**
     * @param  array<string, int>  $breakdown  tokens per slot
     * @param  array<string, RetrievedChunk>  $labelMap  citation label => chunk, for this turn
     */
    public function __construct(public array $messages, public array $breakdown, public array $labelMap = [], public int $prefixMessages = 0) {}

    // Chapter 35, Topic 5: the bytes a provider can cache, whichever way it caches (explicit breakpoint or automatic prefix match): everything up to and including the first cache breakpoint, the system message.
    // Anything that varies per customer or per minute belongs after it; CacheablePrefixTest renders two customers at two times and compares these.
    public function prefixBeforeCacheBreakpoint(): string
    {
        $prefix = array_map(function (array $message) {
            unset($message['cache_control']);

            return $message;
        }, array_slice($this->messages, 0, $this->prefixMessages));

        return json_encode($prefix, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function labelMap(): array
    {
        return $this->labelMap;
    }

    public function tokens(): int
    {
        return array_sum($this->breakdown);
    }
}
