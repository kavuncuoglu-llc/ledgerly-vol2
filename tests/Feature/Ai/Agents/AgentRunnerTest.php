<?php

use App\Ai\Agents\AgentResult;
use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Agents\Trace;
use App\Ai\Schemas\Category;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Events\TicketReceived;
use App\Models\AgentRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RefundTool;

function investigator(array $budget = [], array $tools = ['lookup_invoice', 'list_invoices', 'open_ticket', 'account_status']): InvestigateAgent
{
    return new InvestigateAgent(Category::Billing, app(ToolRegistry::class)->only($tools), $budget + config('ai.agents.triage'));
}

function runInvestigation(User $user, array $replies, array $budget = [], array $tools = ['lookup_invoice', 'list_invoices', 'open_ticket', 'account_status']): AgentResult
{
    fakeChatSequence(...$replies);

    return app(AgentRunner::class)->run(
        investigator($budget, $tools),
        AgentState::fresh('Gather the facts needed to resolve: Payment shows unpaid', ['invoice_number' => '4471']),
        new ToolContext($user, source: 'triage', triageRunId: 99),
    );
}

beforeEach(function () {
    Sleep::fake();

    Event::fake([TicketReceived::class]);

    [$this->user, $this->invoice, $this->customer] = customerWithInvoice();
});

it('loops until the model declares itself done, and writes what it learned into the state', function () {
    $result = runInvestigation($this->user, [
        toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
        toolCallsBody([['c2', 'account_status', ['account' => null]]]),
        chatBody('INV-4471 is overdue by 18 days with a late fee. Whether the client paid is unknown.'),
    ]);

    $findings = $result->state->findings();

    expect($result->status)->toBe(AgentResult::DONE)
        ->and($result->rounds)->toBe(3)
        ->and($result->content())->toContain('overdue by 18 days')
        ->and($findings['invoices'])->toHaveCount(1)
        ->and($findings['invoices'][0])->toMatchArray(['number' => 'INV-4471', 'status' => 'overdue', 'days_overdue' => 18])
        ->and($findings['account'])->toMatchArray(['plan' => $this->customer->plan, 'seats' => $this->customer->seats]);
});

it('gives the model a goal, the fields and what is established so far, and appends the turns', function () {
    runInvestigation($this->user, [
        toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
        chatBody('Done.'),
    ]);

    [$first, $second] = sentChatBodies();

    expect($first['messages'][0]['role'])->toBe('system')
        ->and($first['messages'][1]['content'])->toContain('Goal: Gather the facts needed to resolve')->toContain('"invoice_number":"4471"')->toContain('Established so far: nothing yet')
        ->and($second['messages'][1]['content'])->toContain('Established so far: {"invoices"')
        ->and(collect($second['messages'])->pluck('role')->all())->toBe(['system', 'user', 'assistant', 'tool'])
        ->and($first['temperature'])->toBe(0)
        ->and(collect($first['tools'])->pluck('function.name')->all())->toBe(['account_status', 'list_invoices', 'lookup_invoice', 'open_ticket']);
});

it('persists the run with its state, trace and budget, and the trace reads like a log', function () {
    $result = runInvestigation($this->user, [
        toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
        chatBody('Done.'),
    ]);

    $run = AgentRun::findOrFail($result->runId);

    $log = Trace::render($run->trace['entries']);

    expect($run->status)->toBe('done')
        ->and([$run->agent, $run->feature, $run->rounds, $run->user_id, $run->tenant_id])->toBe(['triage', 'triage.investigate', 2, $this->user->id, $this->user->tenant_id])
        ->and($run->tokens)->toBe((310 + 31) + (190 + 24))
        ->and($run->state['findings']['invoices'][0]['number'])->toBe('INV-4471')
        ->and($log)->toContain('goal: Gather the facts needed to resolve')
        ->toContain('r1 decision → lookup_invoice(invoice_number: "4471", month: null)')
        ->toContain('result: ')
        ->toContain('r2 decision → done: Done.');
});

it('audits and gates every tool the way Chapter 11 does', function () {
    runInvestigation($this->user, [
        toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
        chatBody('Done.'),
    ]);

    $call = ToolCall::sole();

    expect([$call->tool, $call->decision, $call->source])->toBe(['lookup_invoice', 'allowed', 'triage']);
});

it('stops at the round cap and returns what it has, as a normal outcome', function () {
    $lookup = toolCallsBody([['c', 'list_invoices', ['status' => null, 'months' => null]]]);

    $result = runInvestigation($this->user, [$lookup, $lookup, $lookup, $lookup], ['max_rounds' => 2]);

    expect($result->status)->toBe(AgentResult::EXHAUSTED)
        ->and($result->reason)->toBe('rounds')
        ->and($result->rounds)->toBe(2)
        ->and($result->state->findings['invoices_listed'])->toBeGreaterThan(0)
        ->and(AgentRun::findOrFail($result->runId)->status)->toBe('exhausted')
        ->and(Trace::render($result->trace->entries()))->toContain('budget: max_rounds reached → run ends (exhausted: rounds)');
});

it('stops on the token budget', function () {
    $lookup = toolCallsBody([['c', 'list_invoices', ['status' => null, 'months' => null]]], usage: ['prompt_tokens' => 900, 'completion_tokens' => 200]);

    $result = runInvestigation($this->user, [$lookup, chatBody('never reached')], ['max_tokens' => 1000]);

    expect($result->reason)->toBe('tokens')
        ->and(count(sentChatBodies()))->toBe(1);
});

it('stops on the write budget: the second open_ticket is an idempotent no-op but still counts', function () {
    $open = fn (string $id, string $summary) => toolCallsBody([[$id, 'open_ticket', ['summary' => $summary, 'invoice_number' => null]]]);

    $result = runInvestigation($this->user, [
        $open('c1', 'Payment reconciliation for INV-4471'),
        $open('c1', 'Follow up with client re: payment proof'),
        chatBody('never reached'),
    ], ['max_writes' => 2]);

    expect($result->reason)->toBe('writes')
        ->and($result->writes)->toBe(2)
        ->and($result->state->findings['tickets'])->toHaveCount(1)                    // the same tool call id: the idempotency key made the second a no-op
        ->and(Ticket::count())->toBe(1)
        ->and(Trace::render($result->trace->entries()))->toContain('max_writes reached');

    Event::assertNotDispatched(TicketReceived::class);                                    // a ticket opened by a triage run is not triaged again, but by a person
});

it('stops a model that repeats itself: two identical states in a row is stuck', function () {
    $lookup = toolCallsBody([['c', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]);

    $result = runInvestigation($this->user, [$lookup, $lookup, $lookup, $lookup, $lookup, chatBody('never')], ['max_rounds' => 10]);

    expect($result->reason)->toBe('no_progress')
        ->and($result->rounds)->toBe(3)
        ->and($result->state->findings['invoices'])->toHaveCount(1);
});

it('stops on time', function () {
    $lookup = toolCallsBody([['c', 'list_invoices', ['status' => null, 'months' => null]]]);

    $result = runInvestigation($this->user, [$lookup, chatBody('never')], ['max_seconds' => 0]);

    expect($result->reason)->toBe('time')
        ->and(count(sentChatBodies()))->toBe(0);
});

it('only offers and runs the tools the router chose', function () {
    $result = runInvestigation($this->user, [
        toolCallsBody([['c1', 'open_ticket', ['summary' => 'Not allowed here', 'invoice_number' => null]]]),
        chatBody('Done.'),
    ], tools: ['lookup_invoice']);

    expect(collect(sentChatBodies()[0]['tools'])->pluck('function.name')->all())->toBe(['lookup_invoice'])
        ->and(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true)['error'])->toBe('unknown tool')
        ->and(Ticket::count())->toBe(0)
        ->and($result->state->findings['errors'][0])->toBe(['tool' => 'open_ticket', 'error' => 'unknown tool']);
});

it('never reads another customer\'s invoice, whatever number the model asks for', function () {
    Invoice::factory()->for(Customer::factory()->create())->overdue(50)->create(['number' => 'INV-9999']);

    $result = runInvestigation($this->user, [
        toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '9999', 'month' => null]]]),
        chatBody('Done.'),
    ]);

    expect($result->state->findings)->toHaveKey('invoice_not_found')
        ->and($result->state->findings)->not->toHaveKey('invoices')
        ->and(json_encode($result->state->toArray()))->not->toContain('INV-9999');
});

it('carries the agent_run on a failure, then rethrows', function () {
    Http::fake(['*/chat/completions' => Http::response('down', 500)]);

    expect(fn () => app(AgentRunner::class)->run(investigator(), AgentState::fresh('goal'), new ToolContext($this->user, source: 'triage')))
        ->toThrow(Exception::class);

    expect(AgentRun::sole()->status)->toBe('failed');
});

it('loads a persisted state back by run id', function () {
    $result = runInvestigation($this->user, [
        toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
        chatBody('Done.'),
    ]);

    $loaded = AgentState::load($result->runId);

    expect($loaded->id)->toBe($result->state->id)
        ->and($loaded->hash())->toBe($result->state->hash())
        ->and($loaded->messages)->toHaveCount(2);                                         // the investigator keeps its turns, so a paused run can resume
});

describe('the assistant is an agent too', function () {
    it('writes its runs to agent_runs without the messages, which the conversation already has', function () {
        fakeChatSequence(
            toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('INV-4471 is 18 days overdue.'),
        );

        askAssistant($this->user, ['question' => 'Why is invoice 4471 late?'])->assertOk();

        $run = AgentRun::where('agent', 'support')->sole();

        expect($run->status)->toBe('done')
            ->and($run->rounds)->toBe(2)
            ->and($run->state['messages'])->toBe([])
            ->and($run->conversation_id)->not->toBeNull()
            ->and(Trace::render($run->trace['entries']))->toContain('r1 decision → lookup_invoice(');
    });

    it('is bounded by the support budget: one write, then it must answer', function () {
        $open = fn (string $id) => toolCallsBody([[$id, 'open_ticket', ['summary' => 'Payment problem '.$id, 'invoice_number' => null]]]);

        fakeChatSequence($open('c1'), $open('c2'), chatBody('I opened a ticket.'));

        askAssistant($this->user, ['question' => 'Log my payment problem please'])->assertOk()->assertJsonPath('truncated', true);

        $bodies = sentChatBodies();

        expect(AgentRun::where('agent', 'support')->sole()->reason)->toBe('writes')
            ->and($bodies)->toHaveCount(2)                                                     // one round, the budget ended the loop, one last call
            ->and($bodies[1]['tool_choice'])->toBe('none')
            ->and(ModelCall::where('feature', 'support.reply')->count())->toBe(2);
    });
});

describe('approvals inside a run', function () {
    it('pauses on a high-risk write, saves the state, and a person\'s yes lets the loop go on from the saved rounds', function () {
        bindTools(new RefundTool);

        RefundTool::$runs = 0;

        fakeChatSequence(toolCallsBody([['call_9', 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6]]]));

        $agent = new InvestigateAgent(Category::Billing, app(ToolRegistry::class)->only(['issue_refund']), config('ai.agents.triage'));

        $result = app(AgentRunner::class)->run($agent, AgentState::fresh('Resolve the late fee'), new ToolContext($this->user, source: 'triage', triageRunId: 5));

        expect($result->status)->toBe(AgentResult::PAUSED)
            ->and($result->pending)->toHaveCount(1)
            ->and($result->pending[0]->summary)->toBe('Refund $21.60 late fee on invoice #4471')
            ->and(RefundTool::$runs)->toBe(0)                                        // nothing happened yet
            ->and(AgentRun::findOrFail($result->runId)->status)->toBe('paused')
            ->and(Trace::render($result->trace->entries()))->toContain('paused, waiting for approval');

        $loaded = AgentState::load($result->runId);

        Http::swap(new Factory);

        Http::fake(['*/chat/completions' => Http::response(chatBody('Refund recorded.'))]);

        $resumed = app(AgentRunner::class)->resume($agent, $loaded->withMessage(['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => '{"ok":true}']), new ToolContext($this->user, source: 'triage'), roundsAlreadyUsed: $result->rounds);

        expect($resumed->status)->toBe(AgentResult::DONE)
            ->and($resumed->rounds)->toBe(2);                                                       // the paused round counts against the budget
    });
});
