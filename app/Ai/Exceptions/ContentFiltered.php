<?php

namespace App\Ai\Exceptions;

final class ContentFiltered extends AiRequestFailed
{
    public function retryable(): bool
    {
        return false;
    }
}
