<?php

namespace App\Ai\Contracts;

final readonly class Usage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public ?int $cachedTokens = null,
        public ?int $reasoningTokens = null,
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
        );
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
        ], fn ($count) => $count !== null);
    }
}
