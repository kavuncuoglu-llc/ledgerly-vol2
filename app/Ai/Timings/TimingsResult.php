<?php

namespace App\Ai\Timings;

final class TimingsResult
{
    public function __construct(
        public readonly string $api,
        public readonly string $model,
        public readonly string $content,
        public readonly string $finishReason,
        public readonly ModelTimings $timings,
    ) {}

    public function usage(): array
    {
        return [
            'prompt_tokens' => $this->timings->promptTokens,
            'completion_tokens' => $this->timings->completionTokens,
        ];
    }
}
