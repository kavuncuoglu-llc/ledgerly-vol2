<?php

namespace Database\Seeders\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

final class SeededRandom
{
    private Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->randomizer->getInt(0, 999999) < (int) round($probability * 1000000);
    }

    public function pick(array $items): mixed
    {
        $values = array_values($items);

        return $values[$this->randomizer->getInt(0, count($values) - 1)];
    }

    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray(array_values($items));
    }

    public function weighted(array $weights): int|string
    {
        $roll = $this->randomizer->getInt(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
