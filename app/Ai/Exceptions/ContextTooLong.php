<?php

namespace App\Ai\Exceptions;

final class ContextTooLong extends AiRequestFailed
{
    public function retryable(): bool
    {
        return false;                                  // sending the same prompt again overflows the same window
    }
}
