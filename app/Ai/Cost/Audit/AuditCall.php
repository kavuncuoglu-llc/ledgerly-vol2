<?php

namespace App\Ai\Cost\Audit;

use Carbon\CarbonImmutable;

// One model_calls row with its cost worked out from the price map: the unit every detector reads.
final readonly class AuditCall
{
    public function __construct(
        public int $id,
        public string $feature,
        public string $provider,
        public string $model,
        public ?int $conversationId,
        public ?string $jobUuid,
        public ?int $attempt,
        public ?string $promptVersion,
        public int $inputTokens,
        public int $cachedInputTokens,
        public int $outputTokens,
        public float $usd,
        public ?string $traceId,
        public CarbonImmutable $at,
    ) {}

    // What the call belongs to: a conversation, else the queued job, else itself.
    public function unit(): string
    {
        if ($this->conversationId !== null) {
            return 'conversation:'.$this->conversationId;
        }

        if ($this->jobUuid !== null) {
            return 'job:'.$this->jobUuid;
        }

        return 'call:'.$this->id;
    }
}
