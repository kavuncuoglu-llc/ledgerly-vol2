<?php

namespace App\Ai\Contracts;

final readonly class Usage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public ?int $cachedTokens = null,
        public ?int $reasoningTokens = null,
        public ?int $cacheWriteTokens = null,
    ) {}

    public static function fromArray(array $usage): self
    {
        return new self(
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            cachedTokens: isset($usage['prompt_tokens_details']['cached_tokens'])
                ? (int) $usage['prompt_tokens_details']['cached_tokens']
                : null,
            reasoningTokens: isset($usage['completion_tokens_details']['reasoning_tokens'])
                ? (int) $usage['completion_tokens_details']['reasoning_tokens']
                : null,
            cacheWriteTokens: self::cacheWrite($usage),
        );
    }

    // Chapter 35: the tokens a provider billed at its cache-write price. OpenAI-compatible servers that report them put them in prompt_tokens_details; Anthropic's own field is cache_creation_input_tokens.
    private static function cacheWrite(array $usage): ?int
    {
        $written = $usage['prompt_tokens_details']['cache_write_tokens'] ?? $usage['cache_creation_input_tokens'] ?? $usage['cache_write_tokens'] ?? null;

        return $written === null
            ? null
            : (int) $written;
    }

    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function toArray(): array
    {
        return array_filter([
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cached_tokens' => $this->cachedTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ], fn ($count) => $count !== null);
    }
}
