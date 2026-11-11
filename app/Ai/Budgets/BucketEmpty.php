<?php

namespace App\Ai\Budgets;

use RuntimeException;

// Chapter 36, Topic 6: the provider's tokens-per-minute bucket is short. $waitMs is how long until it has refilled; a job uses it as its backoff.
final class BucketEmpty extends RuntimeException
{
    public function __construct(public readonly string $provider, public readonly int $waitMs)
    {
        parent::__construct("The tokens-per-minute bucket of [{$provider}] is empty; try again in {$waitMs} ms.");
    }

    public function retryAfterSeconds(): int
    {
        return max(1, (int) ceil($this->waitMs / 1000));
    }
}
