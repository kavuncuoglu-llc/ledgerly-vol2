<?php

namespace App\Ai\Contracts\Capabilities;

// Chapter 23, Topic 9: constrained decoding by a GBNF grammar, which only llama-server offers. The caller sends a prompt already rendered through the model's chat template, because this is the engine's /completion endpoint.
interface SupportsGrammar
{
    /** @param array<string, mixed> $options sampling: temperature, top_p, seed */
    public function completeWithGrammar(string $prompt, string $grammar, int $maxTokens = 4, array $options = [], string $feature = 'grammar'): string;
}
