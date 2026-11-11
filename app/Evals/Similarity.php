<?php

namespace App\Evals;

use App\Ai\Contracts\EmbedKind;
use App\Facades\Ai;

// Chapter 29, Topic 5: the weakest gate. A low floor only catches an answer that is about something else entirely.
final class Similarity
{
    public function between(string $answer, string $reference): float
    {
        if ($answer === '') {
            return 0.0;
        }

        if ($reference === '') {
            return 1.0;
        }

        [$a, $b] = Ai::embed()->embed([$answer, $reference], EmbedKind::Document);

        $dot = 0.0;
        $lengthA = 0.0;
        $lengthB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $lengthA += $value * $value;
            $lengthB += $b[$i] * $b[$i];
        }

        $product = sqrt($lengthA) * sqrt($lengthB);

        return $product > 0
            ? $dot / $product
            : 0.0;
    }
}
