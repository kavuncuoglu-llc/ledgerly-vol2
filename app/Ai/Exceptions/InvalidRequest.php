<?php

namespace App\Ai\Exceptions;

final class InvalidRequest extends AiRequestFailed
{
    public function retryable(): bool
    {
        return false;                                  // 400, 401, 403, 404, 422: our request or our key is wrong
    }
}
