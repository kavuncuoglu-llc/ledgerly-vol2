<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Prompts\InvestigatePrompt;
use App\Ai\Schemas\Category;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;

// The one agentic step of triage: it may look up invoices and the account, and open a ticket, to attach findings. Everything it learns goes into the state, not only the history.
final class InvestigateAgent implements Agent
{
    public function __construct(
        private Category $category,
        private ToolRegistry $tools,
        private array $budget,
    ) {}

    public function name(): string
    {
        return 'triage';
    }

    public function feature(): string
    {
        return 'triage.investigate';
    }

    public function budget(int $roundsAlreadyUsed = 0): AgentBudget
    {
        return AgentBudget::fromArray($this->budget, $roundsAlreadyUsed, 'triage');
    }

    public function tools(): ToolRegistry
    {
        return $this->tools;
    }

    public function prompt(AgentState $state): array
    {
        $established = $state->findings === []
            ? 'nothing yet'
            : json_encode($state->findings, JSON_UNESCAPED_SLASHES);

        return [
            ['role' => 'system', 'content' => InvestigatePrompt::system()],
            ['role' => 'user', 'content' => "Category: {$this->category->value}\nGoal: {$state->goal}\nWhat the customer wrote, as fields: ".json_encode($state->facts, JSON_UNESCAPED_SLASHES)."\nEstablished so far: {$established}"],
            ...$state->messages,
        ];
    }

    public function options(): array
    {
        return ['profile' => 'deterministic', 'max_tokens' => 300, 'prompt_version' => InvestigatePrompt::VERSION];
    }

    public function assistantTurn(AgentState $state, ChatResponse $response): AgentState
    {
        return $state->withMessage(['role' => 'assistant', 'content' => $response->content, 'tool_calls' => $response->toolCalls]);
    }

    public function observe(AgentState $state, array $call, ToolResult $result): AgentState
    {
        $name = (string) ($call['function']['name'] ?? '');

        $state = $state->withMessage(['role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => $result->toJson()]);

        if (! $result->ok && $result->text === null) {
            return $state->withFinding('errors', ['tool' => $name, 'error' => $result->error], append: true);
        }

        return match ($name) {
            'lookup_invoice' => $this->invoiceFound($state, $result),
            'list_invoices' => $state->withFinding('invoices_listed', $this->listedCount((string) $result->text)),
            'open_ticket' => $this->ticketOpened($state, $result),
            'account_status' => $state->withFinding('account', $result->data),
            default => $state,
        };
    }

    public function persists(): bool
    {
        return true;
    }

    public function keepsMessages(): bool
    {
        return true;                                                                // a paused triage resumes from its own turns
    }

    private function invoiceFound(AgentState $state, ToolResult $result): AgentState
    {
        if (! ($result->data['found'] ?? false)) {
            return $state->withFinding('invoice_not_found', true);
        }

        $invoice = $result->data['invoice'];

        foreach ($state->findings['invoices'] ?? [] as $known) {
            if ($known['number'] === $invoice['number']) {
                return $state;                                                       // the same lookup twice teaches nothing
            }
        }

        return $state->withFinding('invoices', [
            'number' => $invoice['number'],
            'status' => $invoice['status'],
            'days_overdue' => $invoice['days_overdue'],
            'total' => $invoice['total'],
            'late_fee' => $invoice['late_fee'],
        ], append: true);
    }

    private function ticketOpened(AgentState $state, ToolResult $result): AgentState
    {
        $entry = ['number' => $result->data['ticket_number'], 'created' => (bool) ($result->data['created'] ?? false)];

        foreach ($state->findings['tickets'] ?? [] as $known) {
            if ($known['number'] === $entry['number']) {
                return $state;
            }
        }

        return $state->withFinding('tickets', $entry, append: true);
    }

    private function listedCount(string $text): int
    {
        return preg_match('/\[(\d+)\]/', $text, $match) === 1
            ? (int) $match[1]
            : 0;
    }
}
