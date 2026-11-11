<?php

namespace App\Ai\Distillation;

final readonly class Answer
{
    /** @param list<object> $chunks the passages the teacher was shown (RetrievedChunk)
     * @param list<string> $labels their labels, as Chapter 29's gates read them */
    public function __construct(public string $text, public array $chunks, public array $labels, public int $inputTokens = 0) {}
}
