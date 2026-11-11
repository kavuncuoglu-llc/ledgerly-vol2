<?php

namespace App\Ai\Training\TemplateCheck;

// A prompt as a model server tokenizes it. Ollama only reports how many tokens it evaluated, so `ids` is empty there and the comparison is by count.
final readonly class RenderedPrompt
{
    /** @param list<int> $ids */
    public function __construct(
        public int $count,
        public array $ids = [],
        public string $text = '',
    ) {}

    public static function of(array $ids, string $text = ''): self
    {
        return new self(count($ids), $ids, $text);
    }
}
