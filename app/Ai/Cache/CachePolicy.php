<?php

namespace App\Ai\Cache;

use App\Ai\Schemas\Category;
use Carbon\CarbonInterface;

// Which questions may be answered from the cache, at what similarity, and which answers may go into it
final class CachePolicy
{
    private const ACCOUNT_SPECIFIC = [
        '/\b(my|mine|our|ours)\b/i',                                   // "my March invoice"
        '/\binv(oice)?[-\s]?#?\d+\b/i',                                // an invoice number
        '/\b\d{4,}\b/',                                                // any long number: an id, an amount, a year
        '/#\s?\d{3,}/',                                                // "#4471"
        '/\bT-\d{3,}\b/',                                              // a ticket number
        '/\b\d{4}-\d{2}-\d{2}\b/',                                     // an ISO date
        '/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b/',                          // 4/20, 04/20/2026
        '/\b(yesterday|today|tomorrow|last (week|month|year)|this (week|month|year))\b/i',
        '/@/',                                                         // an email address
    ];

    private const ESCAPE_HATCH = '/\b(support ticket|open a ticket|can\'t help|cannot help|outside what I can help|don\'t have that information|not in the (provided )?context)\b/i';

    public function enabled(): bool
    {
        return (bool) config('ai.semantic_cache.enabled');
    }

    /** @return Category[] */
    public function categories(): array
    {
        return array_values(array_filter(array_map(
            fn (string $label) => Category::tryFrom($label),
            config('ai.semantic_cache.categories', []),
        )));
    }

    public function cacheable(Category $category): bool
    {
        return in_array($category, $this->categories(), true);
    }

    public function threshold(Category $category): float
    {
        return (float) (config('ai.semantic_cache.thresholds', [])[$category->value] ?? config('ai.semantic_cache.threshold'));
    }

    public function tenantScoped(Category $category): bool
    {
        return in_array($category->value, config('ai.semantic_cache.tenant_categories', []), true);
    }

    public function isGeneric(string $question): bool
    {
        if (mb_strlen($question) > 300) {
            return false;
        }

        foreach (self::ACCOUNT_SPECIFIC as $pattern) {
            if (preg_match($pattern, $question) === 1) {
                return false;
            }
        }

        return true;
    }

    public function worthStoring(string $answer, bool $grounded): bool
    {
        if (trim($answer) === '') {
            return false;
        }

        if (preg_match(self::ESCAPE_HATCH, $answer) === 1) {
            return false;                                              // never cache "I don't know"
        }

        if (config('ai.semantic_cache.require_context') && ! $grounded) {
            return false;
        }

        return true;
    }

    public function expiresAt(): ?CarbonInterface
    {
        $days = config('ai.semantic_cache.ttl_days');

        return $days
            ? now()->addDays($days)
            : null;
    }
}
