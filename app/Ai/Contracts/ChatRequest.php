<?php

namespace App\Ai\Contracts;

// What a feature asks for, in names that belong to no provider. Immutable: decorators return a changed copy.
final readonly class ChatRequest
{
    public function __construct(
        public array $messages,
        public string $feature,
        public ?string $model = null,                 // override the driver's default
        public array $options = [],                   // temperature, top_p, max_tokens, seed, stop ...
        public array $tools = [],
        public ?array $responseFormat = null,
        public array $cacheBreakpointsAfter = [],     // message indices; drivers that support it mark them
        public ?string $profile = null,               // a name from config('ai.profiles'); null = the feature's profile, else assistant
        public array $meta = [],                      // for the usage log only, never sent: conversation_id, prompt_version, repaired, context_breakdown, semantic_cache_hit, variant
    ) {}

    // The option bag every call site has used since Chapter 8, sorted into the typed slots.
    public static function make(array $messages, array $options = [], string $feature = 'unknown'): self
    {
        $meta = array_intersect_key($options, array_flip(['conversation_id', 'prompt_version', 'repaired', 'context_breakdown', 'semantic_cache_hit', 'variant']));

        $profile = $options['profile'] ?? null;
        $model = $options['model'] ?? null;
        $tools = $options['tools'] ?? [];
        $format = $options['response_format'] ?? null;

        unset($options['profile'], $options['model'], $options['tools'], $options['response_format'], $options['conversation_id'], $options['prompt_version'], $options['repaired'], $options['context_breakdown'], $options['semantic_cache_hit'], $options['variant']);

        return new self($messages, $feature, $model, $options, $tools, $format, [], $profile, $meta);
    }

    public function with(
        ?array $messages = null,
        ?array $options = null,
        ?string $model = null,
        ?array $tools = null,
        ?string $profile = null,
    ): self {
        return new self(
            $messages ?? $this->messages,
            $this->feature,
            $model ?? $this->model,
            $options ?? $this->options,
            $tools ?? $this->tools,
            $this->responseFormat,
            $this->cacheBreakpointsAfter,
            $profile ?? $this->profile,
            $this->meta,
        );
    }
}
