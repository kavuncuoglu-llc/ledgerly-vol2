<?php

namespace App\Ai\Exceptions;

final class ProviderUnavailable extends AiRequestFailed
{
    public function retryable(): bool
    {
        return true;                                   // overloaded, timed out, or hiccuping: 408, 409, 425, 500, 502, 503, 504, 529
    }
}
