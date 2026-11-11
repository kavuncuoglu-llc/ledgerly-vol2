<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\Capabilities\SendsRawRequests;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use Generator;
use LogicException;

// Cross-cutting behaviour wraps any driver, so the capabilities of the driver underneath have to stay reachable: supports() and the two optional interfaces pass straight through.
abstract class ModelDecorator implements ChatModel, SendsRawRequests, SupportsContextWindowOverride, SupportsGrammar
{
    public function __construct(protected ChatModel $inner) {}

    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->inner->chat($request);
    }

    public function stream(ChatRequest $request): Generator
    {
        return $this->inner->stream($request);
    }

    public function supports(Capability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        if (! $this->inner instanceof SendsRawRequests) {
            throw new LogicException("The '{$this->name()}' provider cannot send raw requests.");
        }

        return $this->inner->raw($method, $path, $body);
    }

    public function withContextWindow(int $tokens): static
    {
        if (! $this->inner instanceof SupportsContextWindowOverride) {
            return $this;                                                          // check supports(Capability::ContextWindowOverride) first; this is the safe no-op
        }

        $clone = clone $this;
        $clone->inner = $this->inner->withContextWindow($tokens);

        return $clone;
    }

    // Check supports(Capability::Grammar) first: a decorator always has the method, the driver underneath may not.
    public function completeWithGrammar(string $prompt, string $grammar, int $maxTokens = 4, array $options = [], string $feature = 'grammar'): string
    {
        if (! $this->inner instanceof SupportsGrammar) {
            throw new LogicException("The '{$this->name()}' provider cannot constrain output with a grammar.");
        }

        return $this->inner->completeWithGrammar($prompt, $grammar, $maxTokens, $options, $feature);
    }

    public function unwrap(): ChatModel
    {
        return $this->inner instanceof self
            ? $this->inner->unwrap()
            : $this->inner;
    }
}
