<?php

namespace App\Ai\Sampling;

final class SampleResult
{
    public function __construct(
        public readonly string $api,
        public readonly string $model,
        public readonly string $content,
        public readonly string $finishReason,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $latencyMs,
    ) {}

    public function usage(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }
}
