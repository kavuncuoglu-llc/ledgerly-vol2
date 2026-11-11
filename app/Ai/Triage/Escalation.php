<?php

namespace App\Ai\Triage;

use App\Ai\Schemas\Category;
use App\Facades\Ai;
use App\Models\AgentRun;

// Chapter 19, Topic 8: the draft of a hard ticket may be written by the provider the features map names under escalate_to. Plain PHP, so the reason can be read; and it only ever
// happens when that provider has been set up. Nothing about a ticket leaves for a hosted model until FRONTIER_* says so.
final class Escalation
{
    /** @return array{provider: string, reason: string}|null */
    public static function for(string $feature, ?Category $category, Urgency $urgency, ?AgentRun $investigation): ?array
    {
        $provider = Ai::escalationFor($feature);

        if ($provider === null) {
            return null;
        }

        if ($investigation !== null && $investigation->status === 'exhausted' && $investigation->rounds >= 2) {
            return ['provider' => $provider, 'reason' => "the investigation ran {$investigation->rounds} rounds without a conclusion ({$investigation->reason})"];
        }

        if ($category === Category::Bug && $urgency === Urgency::Urgent) {
            return ['provider' => $provider, 'reason' => 'an urgent bug'];
        }

        return null;
    }
}
