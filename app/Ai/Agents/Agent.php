<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;

interface Agent
{
    public function name(): string;

    public function feature(): string;

    public function budget(int $roundsAlreadyUsed = 0): AgentBudget;

    public function tools(): ToolRegistry;

    /** @return array<int, array> goal + rendered state + the turns so far */
    public function prompt(AgentState $state): array;

    public function options(): array;

    public function assistantTurn(AgentState $state, ChatResponse $response): AgentState;

    public function observe(AgentState $state, array $call, ToolResult $result): AgentState;

    public function persists(): bool;

    public function keepsMessages(): bool;
}
