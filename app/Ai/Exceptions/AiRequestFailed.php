<?php

namespace App\Ai\Exceptions;

use Illuminate\Http\Client\RequestException;

// Chapter 19, Topic 4: a provider's failures, named by what a caller can do about them. They extend Laravel's RequestException so every command and handler that already
// catches a failed HTTP call (and reads $e->response) keeps working.
abstract class AiRequestFailed extends RequestException
{
    abstract public function retryable(): bool;

    public function retryAfterSeconds(): ?int
    {
        $header = $this->response->header('Retry-After');

        return is_numeric($header)
            ? (int) $header
            : null;
    }
}
