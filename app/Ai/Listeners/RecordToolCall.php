<?php

namespace App\Ai\Listeners;

use App\Ai\Events\ToolCalled;
use App\Models\ToolCall;

final class RecordToolCall
{
    public function handle(ToolCalled $e): void
    {
        ToolCall::create([
            'user_id' => $e->context->user->id,
            'conversation_id' => $e->context->conversationId,
            'tool' => $e->tool,
            'arguments' => $e->arguments,
            'decision' => $e->decision,
            'result' => $e->result->toArray(),
            'source' => $e->context->source,
            'token_name' => $e->context->tokenName,
            'duration_ms' => $e->durationMs,
            'trace_id' => $e->traceId,
            'span_id' => $e->spanId,
        ]);
    }
}
