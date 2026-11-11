<?php

namespace App\Ai\Budgets;

// Chapter 36, Topic 5, Figure 36-5: a feature degrades down a ladder, not off a cliff. Cheaper role, then tighter limits, then cached answers only, and at the bottom help-center search and a link to a person: never a blank error.
// A background feature (nobody is waiting) has no ladder: it pauses until the period resets.
final class Ladder
{
    public const CHEAPER_ROLE = 'cheaper_role';

    public const TIGHTER_LIMITS = 'tighter_limits';

    public const CACHE_ONLY = 'cache_only';

    public const SEARCH = 'search';

    public const PAUSE = 'pause';

    public const BOTTOM = self::SEARCH;

    public const DEFAULT = [self::CHEAPER_ROLE, self::TIGHTER_LIMITS, self::CACHE_ONLY, self::SEARCH];

    public function isBackground(string $feature): bool
    {
        return (bool) ((config('ai.features')[$feature] ?? [])['background'] ?? false);
    }

    /** @return string[] the feature's rungs, top to bottom */
    public function rungs(string $feature): array
    {
        if ($this->isBackground($feature)) {
            return [self::PAUSE];
        }

        return (config('ai.features')[$feature] ?? [])['ladder'] ?? self::DEFAULT;
    }

    // The rung a request that has just found a budget full should fall to: the first one, or the one after the rung it is already on.
    public function next(string $feature, ?string $current = null): string
    {
        $rungs = $this->rungs($feature);

        if ($current === null) {
            return $rungs[0];
        }

        $position = array_search($current, $rungs, true);

        return $rungs[($position === false ? -1 : $position) + 1] ?? end($rungs);
    }

    /** @return string[] $from and every rung below it */
    public function from(string $feature, string $from): array
    {
        $rungs = $this->rungs($feature);

        $position = array_search($from, $rungs, true);

        return array_slice($rungs, $position === false ? count($rungs) - 1 : $position);
    }

    public function cheapest(string $feature): string
    {
        $rungs = $this->rungs($feature);

        return $rungs[0];
    }
}
