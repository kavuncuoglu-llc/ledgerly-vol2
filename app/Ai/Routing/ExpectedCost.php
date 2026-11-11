<?php

namespace App\Ai\Routing;

// Chapter 37, Topic 3: expected cost = the first attempt, plus the share that goes past it times the next tier's cost, and so on up the chain. With a local first attempt at near zero, 30% escalating to mid at $0.002 and 10% going on to
// frontier at $0.013, a billing answer costs about $0.0019 against $0.013 straight to frontier.
final class ExpectedCost
{
    /**
     * @param  string[]  $chain  roles, cheapest first
     * @param  array<string, float>  $passRates  the share of answers each role settles (0 to 1)
     * @param  array<string, float>  $costs  dollars per answer by role
     */
    public static function of(array $chain, array $passRates, array $costs): float
    {
        $reach = 1.0;

        $total = 0.0;

        foreach ($chain as $role) {
            $total += $reach * ($costs[$role] ?? 0.0);

            $reach *= 1 - ($passRates[$role] ?? 1.0);
        }

        return $total;
    }

    // Figure 37-8: what a change really costs. The AI spend per conversation plus the escalation rate times what a person's handling costs.
    public static function totalPerConversation(float $aiCost, float $escalationRate, float $humanCostPerEscalation): float
    {
        return $aiCost + $escalationRate * $humanCostPerEscalation;
    }
}
