<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class ProviderNotConfigured extends InvalidArgumentException
{
    public static function unknown(string $name): self
    {
        return new self("There is no provider named '{$name}' in config/ai.php.");
    }

    public static function incomplete(string $name, string $missing): self
    {
        return new self("The '{$name}' provider has no {$missing}. Set its environment variables (for 'frontier': FRONTIER_URL, FRONTIER_KEY and FRONTIER_MODEL).");
    }

    public static function unsupportedDriver(string $name, string $driver): self
    {
        return new self("The '{$name}' provider uses the '{$driver}' driver, which this repository does not ship. The drivers are openai-compatible and fake; write the others against ChatModel (Chapter 19, Exercise 2).");
    }
}
