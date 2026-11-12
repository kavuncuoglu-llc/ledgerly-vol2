<?php

namespace App\Ai\Drivers\AiSdk;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

// Chapter 47, Topic 8: the SDK's unit is an agent class, so one request becomes one throwaway agent. It carries what the app already assembled: the instructions (the system messages), the history, the tools (described, never run) and the sampling options. Nothing here is a prompt: the prompts stay in the app's prompt classes.
class SdkAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    /** @param list<RelayTool> $tools */
    public function __construct(
        private readonly string $instructions,
        private readonly array $messages,
        private readonly array $tools,
        private readonly array $sampling,
        private readonly array $providerOptions,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function maxSteps(): int
    {
        return 1;
    }

    public function temperature(): ?float
    {
        return $this->sampling['temperature'] ?? null;
    }

    public function topP(): ?float
    {
        return $this->sampling['top_p'] ?? null;
    }

    public function maxTokens(): ?int
    {
        return $this->sampling['max_tokens'] ?? null;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
