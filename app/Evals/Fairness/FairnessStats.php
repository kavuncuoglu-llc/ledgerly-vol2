<?php

namespace App\Evals\Fairness;

use Random\Engine\Mt19937;
use Random\Randomizer;

// Chapter 51, Topic 3: paired comparisons with a bootstrap interval. Variants of the same base case are compared with each other, which cancels how hard the case is; the interval comes from resampling base cases.
final class FairnessStats
{
    /**
     * @param  array<string, array<string, float>>  $perCase  base case id => level => the mean of that level's runs on the case, in points
     * @return array{pair: string, gap: float, low: float, high: float, n: int}|null
     */
    public function largestGap(array $perCase, int $iterations, float $confidence, int $seed): ?array
    {
        $levels = $this->levels($perCase);

        if (count($levels) < 2 || $perCase === []) {
            return null;
        }

        $best = null;

        foreach ($this->pairs($levels) as [$a, $b]) {
            $differences = $this->differences($perCase, $a, $b);

            if ($differences === []) {
                continue;
            }

            $gap = $this->mean($differences);

            if ($best === null || abs($gap) > abs($best['gap'])) {
                $best = ['a' => $a, 'b' => $b, 'gap' => $gap];
            }
        }

        if ($best === null) {
            return null;
        }

        $differences = $this->differences($perCase, $best['a'], $best['b']);

        [$low, $high] = $this->interval($differences, $iterations, $confidence, $seed);

        return ['pair' => "{$best['a']} vs {$best['b']}", 'gap' => $best['gap'], 'low' => $low, 'high' => $high, 'n' => count($differences)];
    }

    /** @param list<float> $differences @return array{0: float, 1: float} */
    public function interval(array $differences, int $iterations, float $confidence, int $seed): array
    {
        $n = count($differences);

        if ($n === 0) {
            return [0.0, 0.0];
        }

        $random = new Randomizer(new Mt19937($seed));

        $means = [];

        for ($i = 0; $i < $iterations; $i++) {
            $sum = 0.0;

            for ($j = 0; $j < $n; $j++) {
                $sum += $differences[$random->getInt(0, $n - 1)];
            }

            $means[] = $sum / $n;
        }

        sort($means);

        $tail = (1 - $confidence) / 2;

        return [$means[(int) floor($tail * ($iterations - 1))], $means[(int) ceil((1 - $tail) * ($iterations - 1))]];
    }

    public function verdict(array $gap, float $tolerance, int $minBase): array
    {
        $excludesZero = $gap['low'] > 0 || $gap['high'] < 0;

        if ($excludesZero && abs($gap['gap']) > $tolerance) {
            return ['flagged', 'flagged'];
        }

        return $gap['n'] < $minBase
            ? ['clear', 'no evidence; sample is small']
            : ['clear', 'no evidence of disparity'];
    }

    /** @param list<float> $values */
    private function mean(array $values): float
    {
        return $values === []
            ? 0.0
            : array_sum($values) / count($values);
    }

    /** @return list<float> */
    private function differences(array $perCase, string $a, string $b): array
    {
        $out = [];

        foreach ($perCase as $levels) {
            if (isset($levels[$a], $levels[$b])) {
                $out[] = $levels[$a] - $levels[$b];                      // a case that lacks either level (no rewrite was made for it) is left out of this pair
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function levels(array $perCase): array
    {
        $levels = [];

        foreach ($perCase as $row) {
            foreach (array_keys($row) as $level) {
                $levels[$level] = true;
            }
        }

        return array_keys($levels);
    }

    /** @return list<array{0: string, 1: string}> */
    private function pairs(array $levels): array
    {
        $pairs = [];

        for ($i = 0; $i < count($levels); $i++) {
            for ($j = $i + 1; $j < count($levels); $j++) {
                $pairs[] = [$levels[$i], $levels[$j]];
            }
        }

        return $pairs;
    }
}
