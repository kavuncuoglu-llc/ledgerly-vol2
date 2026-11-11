<?php

namespace App\Ai\Health;

use App\Ai\AiManager;

// Chapter 25, Topic 7: is the server behind a role answering, and if not, where does the work go? The answer is one of three: the role itself, a named fallback, or the cache-only path.
final class ProviderHealth
{
    public const CACHE_ONLY = 'cache-only';

    public function __construct(
        private CircuitBreaker $breaker,
        private AiManager $ai,
    ) {}

    public function isAvailable(string $provider): bool
    {
        return ! $this->breaker->isOpen($provider);
    }

    // The provider that should get a feature's request right now, or CACHE_ONLY when its server is down and no fallback is usable.
    public function routeFor(string $feature): string
    {
        $provider = $this->ai->providerFor($feature);

        if ($this->isAvailable($provider)) {
            return $provider;
        }

        return $this->fallbackFor($provider, $feature) ?? self::CACHE_ONLY;
    }

    // A fallback is a decision somebody made in .env (AI_FALLBACK_LOCAL), for named features only, and only to a provider that is set up and not itself down.
    // The default is none: with FRONTIER_* set for escalation, customer text still does not go there because a local server fell over unless you said so.
    public function fallbackFor(string $provider, string $feature): ?string
    {
        $fallback = config('ai.fallback.'.$provider);

        if (blank($fallback)) {
            return null;
        }

        if (! in_array($feature, (array) config('ai.fallback.features', []), true)) {
            return null;
        }

        if (! $this->ai->configured((string) $fallback)) {
            return null;
        }

        return $this->isAvailable((string) $fallback)
            ? (string) $fallback
            : null;
    }

    public function canAnswer(string $feature): bool
    {
        return $this->routeFor($feature) !== self::CACHE_ONLY;
    }
}
