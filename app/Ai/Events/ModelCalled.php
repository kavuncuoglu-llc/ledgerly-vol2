<?php

namespace App\Ai\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ModelCalled
{
    use Dispatchable;

    public function __construct(
        public readonly string $feature,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $usage,          // ['prompt_tokens' => .., 'completion_tokens' => ..]
        public readonly string $finishReason,
        public readonly int $latencyMs,
        public readonly ?int $conversationId = null,
        public readonly ?string $promptVersion = null,
        public readonly bool $repaired = false,
        public readonly ?int $cachedTokens = null,            // from usage, or estimated
        public readonly ?int $ttftMs = null,                  // streaming client records first-delta time
        public readonly ?array $contextBreakdown = null,      // ['system' => 440, 'history' => 1620, ...] from ContextAssembler
        public readonly ?bool $semanticCacheHit = null,
        public readonly ?array $request = null,               // Chapter 20: the normalised request, only when ai.debug.record_requests is on
        public readonly ?string $variant = null,              // Chapter 30: the experiment variant that wrote this call
        public readonly ?string $traceId = null,              // Chapter 31: the trace and span current when the call was made, so the audit row joins its span
        public readonly ?string $spanId = null,
    ) {}

    public ?int $modelCallId = null;                          // set by RecordModelCall, so the decorator that dispatched the event can hand the id on (Chapters 30 and 31)

    public ?int $costMicros = null;                           // set by RecordModelCall too: Pulse's cost card records it (Chapter 31, Topic 7)
}
