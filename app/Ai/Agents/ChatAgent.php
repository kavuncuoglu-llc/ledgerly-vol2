<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Streaming\StreamListener;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;

// The Chapter 11 assistant as an agent: the state is the message list, the goal is the customer's question, and nothing persists but the trace.
final class ChatAgent implements Agent, StreamsReplies
{
    public function __construct(private ToolRegistry $tools, private array $options, private string $feature, private ?StreamListener $stream = null) {}

    public function listener(): ?StreamListener
    {
        return $this->stream;
    }

    public function name(): string
    {
        return 'support';
    }

    public function feature(): string
    {
        return $this->feature;
    }

    public function budget(int $roundsAlreadyUsed = 0): AgentBudget
    {
        return AgentBudget::fromConfig('support', $roundsAlreadyUsed);
    }

    public function tools(): ToolRegistry
    {
        return $this->tools;
    }

    public function prompt(AgentState $state): array
    {
        return $state->messages;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function assistantTurn(AgentState $state, ChatResponse $response): AgentState
    {
        return $state
            ->withMessage(['role' => 'assistant', 'content' => $response->content, 'tool_calls' => $response->toolCalls])      // the model's request, verbatim
            ->withProgress(['messages' => count($state->messages) + 1]);
    }

    public function observe(AgentState $state, array $call, ToolResult $result): AgentState
    {
        return $state
            ->withMessage(['role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => $result->toJson()])
            ->withProgress(['messages' => count($state->messages) + 1]);
    }

    public function persists(): bool
    {
        return true;
    }

    public function keepsMessages(): bool
    {
        return false;                                              // the conversation store already has them
    }
}
