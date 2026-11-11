<?php

namespace App\Ai\Exceptions;

final class RateLimited extends AiRequestFailed
{
    public function retryable(): bool
    {
        return true;
    }
}
