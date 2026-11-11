<?php

use App\Ai\Agents\AgentBudget;
use App\Ai\Agents\AgentResult;
use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Schemas\Category;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;
use App\Events\TicketReceived;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

// Chapter 35, Topic 3: limits that cannot silently disappear. The postmortem's cause was a misspelled config key that fell back to "none".
function ceilingRun(User $user, array $replies): AgentResult
{
    fakeChatSequence(...$replies);

    $agent = new InvestigateAgent(Category::Billing, app(ToolRegistry::class)->only(['lookup_invoice', 'list_invoices']), config('ai.agents.triage'));

    return app(AgentRunner::class)->run($agent, AgentState::fresh('Gather the facts', ['invoice_number' => '4471']), new ToolContext($user, source: 'triage', triageRunId: 99));
}

describe('the ceilings in code', function () {
    it('lowers a limit from config but never raises it past the ceiling', function () {
        $budget = new AgentBudget(50, 500_000, 3_600, 2);

        expect($budget->limits())->toBe(['max_rounds' => 8, 'max_tokens' => 60_000, 'max_seconds' => 90, 'max_writes' => 2]);

        $lowered = new AgentBudget(3, 10_000, 30, 1);

        expect($lowered->limits()['max_rounds'])->toBe(3)
            ->and($lowered->limits()['max_tokens'])->toBe(10_000)
            ->and($lowered->limits()['max_seconds'])->toBe(30);
    });

    it('treats a missing or misspelled key as the ceiling, not as no limit', function () {
        $budget = AgentBudget::fromArray(['max_round' => 5, 'max_tokens' => 20_000]);

        expect($budget->limits()['max_rounds'])->toBe(AgentBudget::MAX_ROUNDS)
            ->and($budget->limits()['max_seconds'])->toBe(AgentBudget::MAX_SECONDS)
            ->and($budget->limits()['max_tokens'])->toBe(20_000);
    });

    it('keeps the longer budgets Chapters 17 and 18 gave the triage and reminder agents, in code', function () {
        $triage = AgentBudget::fromArray(['max_rounds' => 99, 'max_tokens' => 999_999, 'max_seconds' => 999, 'max_writes' => 2], agent: 'triage');

        $reminder = AgentBudget::fromConfig('reminder');

        expect($triage->limits())->toBe(['max_rounds' => 8, 'max_tokens' => 30_000, 'max_seconds' => 120, 'max_writes' => 2])
            ->and($reminder->limits()['max_rounds'])->toBe(15);
    });

    it('stops after the same tool error three times in a row', function () {
        $budget = new AgentBudget(8, 60_000, 90, 2);

        $call = ['function' => ['name' => 'lookup_invoice']];

        foreach (range(1, 2) as $time) {
            $budget->recordOutcome($call, ToolResult::error('invoice service timed out'));
        }

        expect($budget->reason())->toBeNull();

        $budget->recordOutcome($call, ToolResult::error('invoice service timed out'));

        expect($budget->reason())->toBe('same_error');
    });

    it('does not count different errors, or an error between two successes', function () {
        $budget = new AgentBudget(8, 60_000, 90, 2);

        $call = ['function' => ['name' => 'lookup_invoice']];

        $budget->recordOutcome($call, ToolResult::error('timed out'));
        $budget->recordOutcome($call, ToolResult::error('not found'));
        $budget->recordOutcome($call, ToolResult::error('timed out'));
        $budget->recordOutcome($call, ToolResult::ok());
        $budget->recordOutcome($call, ToolResult::error('timed out'));

        expect($budget->reason())->toBeNull();
    });
});

describe('a model that never stops calling tools (fooled-model test)', function () {
    beforeEach(function () {
        Sleep::fake();

        Event::fake([TicketReceived::class]);

        [$this->user, $this->invoice] = customerWithInvoice();
    });

    it('ends at the round ceiling even when config says there is none', function () {
        config()->set('ai.agents.triage', ['max_tokens' => 1_000_000, 'max_writes' => 100]);       // no max_rounds, no max_seconds: the lost keys of the postmortem

        $replies = array_map(fn (int $round) => toolCallsBody([["c{$round}", 'list_invoices', ['status' => $round % 2 === 0 ? 'overdue' : 'open']]]), range(1, 60));

        $result = ceilingRun($this->user, $replies);

        expect($result->status)->toBe(AgentResult::EXHAUSTED)
            ->and($result->reason)->toBe('rounds')
            ->and($result->rounds)->toBe(AgentBudget::MAX_ROUNDS)
            ->and(count(sentChatBodies()))->toBe(AgentBudget::MAX_ROUNDS);
    });

    it('ends a run whose tool keeps failing the same way after three tries and hands it to a person', function () {
        $failing = toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => 'INV-NOPE', 'month' => null]]]);

        $result = ceilingRun($this->user, array_fill(0, 30, $failing));

        expect($result->status)->toBe(AgentResult::EXHAUSTED)
            ->and($result->reason)->toBe('same_error')
            ->and($result->rounds)->toBe(3);
    });
});
