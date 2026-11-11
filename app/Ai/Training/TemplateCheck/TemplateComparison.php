<?php

namespace App\Ai\Training\TemplateCheck;

// Chapter 40, Topic 5: the same messages, rendered by the trainer and by the server, must be the same tokens. By id where both sides say which tokens, by count where one only says how many.
final readonly class TemplateComparison
{
    private function __construct(
        public bool $match,
        public string $summary,
        public ?int $firstDifference = null,
    ) {}

    public static function between(RenderedPrompt $training, RenderedPrompt $serving, string $servingName): self
    {
        if ($serving->ids === []) {
            return $training->count === $serving->count
                ? new self(true, "{$training->count} tokens on both sides ({$servingName} reports a count, not the ids)")
                : new self(false, "training {$training->count} tokens, {$servingName} {$serving->count}: the server's template differs from the one the model trained on");
        }

        $limit = min($training->count, $serving->count);

        for ($i = 0; $i < $limit; $i++) {
            if ($training->ids[$i] !== $serving->ids[$i]) {
                return new self(false, "first difference at token {$i}: training {$training->ids[$i]}, {$servingName} {$serving->ids[$i]}", $i);
            }
        }

        if ($training->count !== $serving->count) {
            return new self(false, "the first {$limit} tokens agree, then training has {$training->count} and {$servingName} {$serving->count}", $limit);
        }

        return new self(true, "{$training->count} token ids identical");
    }
}
