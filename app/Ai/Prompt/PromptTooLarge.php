<?php

namespace App\Ai\Prompt;

use RuntimeException;

final class PromptTooLarge extends RuntimeException
{
    public function __construct(public readonly int $promptTokens, public readonly int $window)
    {
        parent::__construct("Prompt of {$promptTokens} tokens leaves no room for the answer in a {$window}-token window.");
    }
}
