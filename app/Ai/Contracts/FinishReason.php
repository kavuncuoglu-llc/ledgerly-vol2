<?php

namespace App\Ai\Contracts;

enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    case ContentFilter = 'content_filter';
    case Truncated = 'truncated';

    // Chapter 19, Topic 4: every provider's vocabulary lands here, and anything unrecognised becomes Truncated: an unknown reason should make you look, not pass as success.
    public static function fromOpenAi(?string $reason): self
    {
        return match ($reason) {
            null, 'stop', 'end_turn', 'STOP', 'done' => self::Stop,
            'length', 'max_tokens', 'MAX_TOKENS' => self::Length,
            'tool_calls', 'tool_use', 'function_call', 'FUNCTION_CALL' => self::ToolCalls,
            'content_filter', 'SAFETY' => self::ContentFilter,
            default => self::Truncated,
        };
    }
}
