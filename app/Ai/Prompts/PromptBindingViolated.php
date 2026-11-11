<?php

namespace App\Ai\Prompts;

use RuntimeException;

final class PromptBindingViolated extends RuntimeException
{
    public static function for(string $provider, string $why): self
    {
        return new self("The system message does not match role [{$provider}]: {$why} (Chapter 41, Topic 5).");
    }
}
