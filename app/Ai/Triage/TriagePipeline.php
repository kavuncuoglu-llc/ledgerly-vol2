<?php

namespace App\Ai\Triage;

use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Prompts\TicketExtractPrompt;
use App\Ai\Schemas\Category;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Ai\Support\Route;
use App\Ai\Support\Router;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

// A workflow with one agentic step: classify (by vote), extract, apply the urgency rule, assign, investigate (billing and account only), draft. A person approves the draft.
final class TriagePipeline
{
    public function __construct(
        private Router $router,
        private StructuredClient $structured,
        private AgentRunner $agents,
        private ToolRegistry $tools,
        private Drafter $drafter,
    ) {}

    public function run(Ticket $ticket, ?TriageRun $run = null): TriageResult
    {
        $started = microtime(true);

        $run ??= TriageRun::create(['event_id' => 'manual-'.Str::uuid(), 'ticket_id' => $ticket->id, 'status' => 'running']);

        try {
            $result = $this->triage($ticket, $run);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'reason' => Str::limit($e->getMessage(), 200), 'seconds' => round(microtime(true) - $started, 2)]);

            throw $e;
        }

        $run->update(['seconds' => round(microtime(true) - $started, 2)]);

        return $result;
    }

    private function triage(Ticket $ticket, TriageRun $run): TriageResult
    {
        $owner = $ticket->customer?->owner;

        if ($owner === null) {
            return $this->handOver($ticket, $run, Route::human('the customer has no owner to act as', []));
        }

        $text = $this->text($ticket);

        $route = $this->router->route($text, $owner);                                       // workflow step: classify with self-consistency

        if ($route->category === null || $route->isHuman() || $route->isDecline()) {
            return $this->handOver($ticket, $run, $route);
        }

        $fields = $this->structured->extract(                                               // workflow step
            TicketData::class,
            TicketExtractPrompt::messages($text),
            ['prompt_version' => TicketExtractPrompt::VERSION],
            feature: 'ticket.extract',
        );

        $urgency = UrgencyRule::evaluate($route->category, $fields, $ticket->customer, $text);          // PHP, no model

        $assignee = Assignment::for($route->category, $urgency);                                         // PHP

        $facts = $this->facts($fields, $ticket);

        [$findings, $agentRun] = $this->investigate($ticket, $owner, $run, $route, $facts);

        $escalation = Escalation::for('triage.draft', $route->category, $urgency, $agentRun);

        $draft = $this->draft($ticket, $owner, $facts, $findings, $route, $escalation['provider'] ?? null);

        $run->update([
            'status' => 'automated',
            'category' => $route->category->value,
            'agreement' => $route->agreement,
            'votes' => $route->votes,
            'urgency' => $urgency->value,
            'assignee' => $assignee,
            'fields' => $facts,
            'findings' => $findings,
            'agent_run_id' => $agentRun?->id,
            'draft' => $draft?->text,
            'draft_status' => $draft === null ? null : 'queued',                            // the review queue: nothing reaches a customer unapproved
            'draft_citations_ok' => $draft?->citationsOk,
            'escalated_to' => $draft === null ? null : ($escalation['provider'] ?? null),
            'escalation_reason' => $draft === null ? null : ($escalation['reason'] ?? null),
            'reason' => $draft === null ? 'no draft: the model did not answer' : null,
        ]);

        $ticket->update(['category' => $route->category->value, 'urgency' => $urgency->value, 'assignee' => $assignee, 'triaged_at' => now()]);

        return new TriageResult($ticket, $run->refresh(), false, null, $urgency, $assignee, $findings, $draft, $agentRun);
    }

    private function handOver(Ticket $ticket, TriageRun $run, Route $route): TriageResult
    {
        $reason = $route->reason ?? 'needs a person';

        $run->update([
            'status' => 'human',
            'category' => $route->category?->value,
            'agreement' => $route->agreement,
            'votes' => $route->votes,
            'reason' => $reason,
        ]);

        $ticket->update(['triaged_at' => now()]);                                           // unlabeled on purpose: a split vote is not a label

        return new TriageResult($ticket, $run->refresh(), true, $reason);
    }

    private function investigate(Ticket $ticket, User $owner, TriageRun $run, Route $route, array $facts): array
    {
        if (! in_array($route->category, [Category::Billing, Category::Account], true)) {
            return [[], null];
        }

        $agent = new InvestigateAgent($route->category, $this->tools->only($route->tools ?? []), config('ai.agents.triage'));

        $result = $this->agents->run(                                                       // the ONE agentic step, bounded
            $agent,
            AgentState::fresh(goal: 'Gather the facts needed to resolve ticket '.$ticket->number, facts: $facts),
            new ToolContext($owner, conversationId: null, source: 'triage', triageRunId: $run->id),
        );

        $findings = $result->state->findings();                                             // exhausted runs still return what they got

        if ($result->content() !== '') {
            $findings['summary'] = trim($result->content());
        }

        $findings['run'] = ['status' => $result->status, 'reason' => $result->reason, 'rounds' => $result->rounds];

        return [$findings, AgentRun::find($result->runId)];
    }

    private function draft(Ticket $ticket, User $owner, array $facts, array $findings, Route $route, ?string $provider = null): ?Draft
    {
        try {
            return $this->drafter->draft($ticket, $owner, $facts, $this->forDraft($findings), $route->area, $provider);
        } catch (Throwable $e) {
            report($e);                                                                     // a missing draft is a smaller failure than a missing triage

            return null;
        }
    }

    private function forDraft(array $findings): array
    {
        unset($findings['run'], $findings['errors']);

        return $findings;
    }

    private function facts(TicketData $fields, Ticket $ticket): array
    {
        return array_filter([
            'ticket' => $ticket->number,
            'invoice_number' => $this->patterned($fields->invoice_number ?? $ticket->invoice_number),
            'amount_mentioned' => $fields->amount_mentioned,
            'date_mentioned' => $fields->date_mentioned,
        ], fn ($value) => $value !== null);
    }

    private function patterned(?string $number): ?string
    {
        return $number !== null && preg_match('/^(INV-)?\d{3,8}$/i', $number) === 1
            ? $number
            : null;                                                                          // the privileged step only ever sees a value shaped like an invoice number
    }

    private function text(Ticket $ticket): string
    {
        return trim(($ticket->subject ? $ticket->subject."\n" : '').($ticket->body ?: (string) $ticket->summary));
    }
}
