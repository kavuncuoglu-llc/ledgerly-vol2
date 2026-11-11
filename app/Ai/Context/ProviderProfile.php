<?php

namespace App\Ai\Context;

// The first provider-specific branch in the app, in one place. Chapter 19's drivers take it over.
final class ProviderProfile
{
    private const BREAKPOINT_PROVIDERS = ['anthropic'];     // explicit cache_control markers; OpenAI and local servers cache by prefix automatically

    public function __construct(private string $provider, private ?bool $cacheBreakpoints = null) {}

    public static function fromConfig(): self
    {
        $forced = config('ai.cache_breakpoints');

        return new self(
            config('ai.provider'),
            $forced === null || $forced === ''
                ? null
                : filter_var($forced, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function supportsCacheBreakpoints(): bool
    {
        return $this->cacheBreakpoints ?? in_array($this->provider, self::BREAKPOINT_PROVIDERS, true);
    }
}
