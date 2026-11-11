<?php

namespace App\Ai\Budgets;

use Illuminate\Support\Str;

// Chapter 36, Topic 3: team comes from config, never guessed from the row. A feature's own `team` setting wins; otherwise the team whose name prefix the feature carries; otherwise `unowned`, which the dashboard shows as its own line.
final class Teams
{
    public static function of(string $feature): string
    {
        $named = (config('ai.features')[$feature] ?? [])['team'] ?? null;                     // feature names contain dots: array keys, not config paths

        if ($named !== null) {
            return (string) $named;
        }

        foreach (config('ai.teams', []) as $team => $settings) {
            foreach ($settings['prefixes'] ?? [] as $prefix) {
                if (Str::startsWith($feature, $prefix)) {
                    return (string) $team;
                }
            }
        }

        return 'unowned';
    }
}
