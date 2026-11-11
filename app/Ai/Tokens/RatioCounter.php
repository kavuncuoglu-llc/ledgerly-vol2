<?php

namespace App\Ai\Tokens;

// estimate, from a ratio you measured
final class RatioCounter implements TokenCounter
{
    // Measure this per model on your own content (Exercise 3). 3.6 is a
    // reasonable starting point for English prose on Llama-family tokenizers.
    public function __construct(private float $charsPerToken = 3.6) {}

    public function count(string $text): int
    {
        return (int) ceil(mb_strlen($text) / $this->charsPerToken);
    }
}
