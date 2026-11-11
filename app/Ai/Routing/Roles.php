<?php

namespace App\Ai\Routing;

// Chapter 37, Topic 2: the book's roles (local, mid, frontier) are the tiers of config('ai.tiers'); anything else in a route is already a provider name (Chapter 44's local-billing-ft).
final class Roles
{
    public static function provider(string $role): string
    {
        return match ($role) {
            'local' => (string) config('ai.tiers.small', 'local'),
            'mid' => (string) config('ai.tiers.mid', 'local-70b'),
            'frontier' => (string) config('ai.tiers.frontier', 'frontier'),
            default => $role,
        };
    }
}
