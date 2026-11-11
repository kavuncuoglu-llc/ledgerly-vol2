<?php

namespace App\Ai\Routing;

use InvalidArgumentException;

// Chapter 37, Topic 2, Figure 37-2: the routing table from golden-set pass rates. For each category, the cheapest role whose pass rate clears the scorecard's floor with a margin starts it; a cheap role that misses the floor by a few points (more than
// `cascade_gap_min`, no more than `cascade_gap_max`) starts a cascade instead, so the 9% it fails go up a tier and the 91% it passes do not pay for them; a role far below the floor is left out; and the chain always continues up to the
// most capable role, then a person. A pure function of its inputs, so a routing change is a reviewed diff of the file it writes.
final class RouteTableBuilder
{
    /**
     * @param  array<string, array<string, float>>  $rates  category => role => golden-set pass rate (0 to 1); a role with no rate for a category was not measured
     * @param  string[]  $roles  cheapest first
     * @param  string[]  $forceCascade  categories to cascade whatever their gap (Exercise 2)
     * @return array<string, array{start: string, chain: string[], buffers: bool, cascade: bool, rates: array<string, float>, why: string}>
     */
    public function build(array $rates, array $roles, float $floor, float $margin, array $forceCascade = [], float $gapMin = 0.02, float $gapMax = 0.10): array
    {
        $routes = [];

        foreach ($rates as $category => $byRole) {
            $this->assertCategoryName((string) $category);

            $routes[$category] = $this->route($category, $byRole, $roles, $floor, $margin, in_array($category, $forceCascade, true), $gapMin, $gapMax);
        }

        ksort($routes);

        return $routes;
    }

    private function assertCategoryName(string $category): void
    {
        if (preg_match('/^[a-z0-9_.-]+$/', $category) === 1) {
            return;
        }

        throw new InvalidArgumentException('A routing category may only contain lowercase letters, digits, underscore, dot and hyphen; got '.var_export($category, true).'.');
    }

    private function route(string $category, array $byRole, array $roles, float $floor, float $margin, bool $forced, float $gapMin, float $gapMax): array
    {
        $measured = array_values(array_filter($roles, fn (string $role) => isset($byRole[$role])));

        $top = $roles[array_key_last($roles)];

        if ($measured === []) {
            return $this->row($top, $roles, false, $byRole, 'no role was measured: the most capable role, then a person');
        }

        $clearing = array_values(array_filter($measured, fn (string $role) => $byRole[$role] >= $floor + $margin - 1e-9));

        if ($clearing === []) {
            return $this->row($top, $roles, false, $byRole, 'no role clears the floor: the most capable role, then a person; add golden cases or improve the prompt');
        }

        $cheapest = $measured[0];

        $start = $clearing[0];

        if ($start === $cheapest) {
            return $this->row($start, $roles, false, $byRole, "{$start} clears the floor with the margin");
        }

        $gap = $floor - $byRole[$cheapest];

        if ($forced || ($gap > $gapMin + 1e-9 && $gap <= $gapMax + 1e-9)) {
            return $this->row($cheapest, $roles, true, $byRole, sprintf('%s passes %d%% against a floor of %d%%: start there and escalate on failed checks', $cheapest, round($byRole[$cheapest] * 100), round($floor * 100)));
        }

        return $this->row($start, $roles, false, $byRole, sprintf('%s is %s the floor; %s is the cheapest role that clears it', $cheapest, $gap <= $gapMin + 1e-9 ? 'a point or two under' : 'far under', $start));
    }

    private function row(string $start, array $roles, bool $cascade, array $byRole, string $why): array
    {
        $chain = array_slice($roles, (int) array_search($start, $roles, true));

        return [
            'start' => $start,
            'chain' => $chain,
            'buffers' => count($chain) > 1,
            'cascade' => $cascade,
            'rates' => array_map(fn ($rate) => round((float) $rate, 4), $byRole),
            'why' => $why,
        ];
    }
}
