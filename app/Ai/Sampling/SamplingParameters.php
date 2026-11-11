<?php

namespace App\Ai\Sampling;

use InvalidArgumentException;

// Every decoding knob from Chapter 5, under the OpenAI-style names. Unset knobs are left to the server's defaults.
final class SamplingParameters
{
    // What an OpenAI-compatible /chat/completions body understands.
    private const OPENAI_KEYS = [
        'temperature',
        'top_p',
        'seed',
        'stop',
        'max_tokens',
        'frequency_penalty',
        'presence_penalty',
    ];

    // Knobs the /v1/ shape has no field for; Ollama takes them as `options` on /api/chat.
    private const NATIVE_ONLY_KEYS = [
        'top_k',
        'min_p',
        'repeat_penalty',
    ];

    private const OLLAMA_RENAMES = [
        'max_tokens' => 'num_predict',
    ];

    public function __construct(private array $values = []) {}

    public static function fromProfile(string $name): self
    {
        $profile = config("ai.profiles.{$name}");

        if (! is_array($profile)) {
            throw new InvalidArgumentException("No sampling profile '{$name}' in config/ai.php.");
        }

        return new self($profile);
    }

    public function merge(array $overrides): self
    {
        return new self(array_merge($this->values, array_filter($overrides, fn ($value) => $value !== null && $value !== [])));
    }

    public function all(): array
    {
        return $this->values;
    }

    public function openAiBody(): array
    {
        return array_intersect_key($this->values, array_flip(self::OPENAI_KEYS));
    }

    public function ollamaOptions(): array
    {
        $options = [];

        foreach ($this->values as $key => $value) {
            $options[self::OLLAMA_RENAMES[$key] ?? $key] = $value;
        }

        return $options;
    }

    public function nativeOnlyKeys(): array
    {
        return array_values(array_intersect(array_keys($this->values), self::NATIVE_ONLY_KEYS));
    }

    public function needsNativeApi(): bool
    {
        return $this->nativeOnlyKeys() !== [];
    }
}
