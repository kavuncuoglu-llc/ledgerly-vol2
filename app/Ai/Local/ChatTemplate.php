<?php

namespace App\Ai\Local;

// The template Ollama stores with a model (Go template syntax), plus the few facts about it Chapter 4 points at.
final class ChatTemplate
{
    public function __construct(
        public readonly string $model,
        public readonly string $template,
        public readonly string $family = '',
        public readonly string $parameters = '',
    ) {}

    public static function fromShow(string $model, array $show): self
    {
        return new self(
            model: $model,
            template: (string) ($show['template'] ?? ''),
            family: (string) ($show['details']['family'] ?? ''),
            parameters: (string) ($show['parameters'] ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return trim($this->template) === '';
    }

    public function specialTokens(): array
    {
        preg_match_all('/<\|[A-Za-z0-9_]+\|>|<(?:start|end)_of_turn>|\[\/?INST\]/', $this->template, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function hasSystemSlot(): bool
    {
        return str_contains($this->template, '.System');
    }

    public function hasMessagesLoop(): bool
    {
        return str_contains($this->template, '.Messages');
    }

    public function hasToolsSection(): bool
    {
        return str_contains($this->template, '.Tools');
    }

    // A chat template ends by opening the assistant's turn: that open header is the model's cue to write.
    public function endsWithOpenAssistantTurn(): bool
    {
        return (bool) preg_match('/assistant[^{}]*$/s', rtrim($this->template));
    }
}
