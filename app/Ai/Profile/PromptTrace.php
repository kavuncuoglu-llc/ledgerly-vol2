<?php

namespace App\Ai\Profile;

// What one prompt looked like from the outside: when it was sent, when the first token came, when each token came, and what the server said it counted.
final readonly class PromptTrace
{
    /** @param list<float> $deltaTimes unix seconds, one per streamed chunk (a chunk is one token on llama.cpp-based servers) */
    public function __construct(
        public string $name,
        public float $sentAt,
        public float $firstDeltaAt,
        public float $lastDeltaAt,
        public array $deltaTimes,
        public int $promptTokens,
        public int $completionTokens,
    ) {}

    public function prefillSeconds(): float
    {
        return max(0.0, $this->firstDeltaAt - $this->sentAt);
    }

    public function decodeSeconds(): float
    {
        return max(0.0, $this->lastDeltaAt - $this->firstDeltaAt);
    }

    // the server's count when it gives one, else one token per chunk
    public function generated(): int
    {
        return $this->completionTokens > 0
            ? $this->completionTokens
            : count($this->deltaTimes);
    }
}
