<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Events\TicketReceived;
use App\Models\Invoice;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\Support\BrokenTool;
use Tests\Support\OnceTool;
use Tests\Support\RefundTool;

function runTools(User $user, string $question = 'Why is my invoice late?', ?int $conversationId = null): ToolRunResult
{
    return app(ToolRunner::class)->run(
        messages: [['role' => 'user', 'content' => $question]],
        ctx: new ToolContext($user, $conversationId),
        options: ['profile' => 'assistant', 'prompt_version' => 'test.1'],
        feature: 'support.reply',
    );
}

beforeEach(function () {
    Event::fake([TicketReceived::class]);

    RefundTool::$runs = 0;
    OnceTool::$runs = 0;
});

describe('the loop', function () {
    it('answers directly when the model asks for nothing', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(chatBody('Plain answer.'));

        $result = runTools($user);

        expect($result->response->content)->toBe('Plain answer.')
            ->and($result->rounds)->toBe(1)
            ->and($result->hitCap)->toBeFalse()
            ->and($result->answer())->toBe('Plain answer.');

        expect(ToolCall::count())->toBe(0);
    });

    it('sends the tool definitions with the request', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(chatBody('Plain answer.'));

        runTools($user);

        $tools = sentChatBodies()[0]['tools'];

        $lookup = collect($tools)->firstWhere('function.name', 'lookup_invoice');

        expect(collect($tools)->pluck('function.name')->all())->toBe(['account_status', 'change_billing_email', 'check_payment_status', 'issue_credit', 'list_invoices', 'lookup_invoice', 'open_ticket', 'resend_invoices', 'retry_payment'])   // sorted by name: a stable prefix for the prompt cache
            ->and($lookup['type'])->toBe('function')
            ->and($lookup['function']['parameters']['required'])->toBe(['invoice_number', 'month'])
            ->and($lookup['function']['parameters']['additionalProperties'])->toBeFalse()
            ->and($lookup['function']['description'])->toContain('Do not use for general questions');
    });

    it('runs a tool, appends the request verbatim and the result by id, and calls the model again', function () {
        [$user] = customerWithInvoice('INV-4471');

        fakeChatSequence(
            toolCallsBody([['call_8a1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('Invoice #4471 is 18 days overdue.'),
        );

        $result = runTools($user);

        $bodies = sentChatBodies();

        expect($bodies)->toHaveCount(2)
            ->and($result->rounds)->toBe(2)
            ->and($result->response->content)->toBe('Invoice #4471 is 18 days overdue.');

        $second = $bodies[1]['messages'];

        expect($second[1]['role'])->toBe('assistant')
            ->and($second[1]['content'])->toBeNull()
            ->and($second[1]['tool_calls'][0]['id'])->toBe('call_8a1')
            ->and($second[1]['tool_calls'][0]['function']['name'])->toBe('lookup_invoice')
            ->and($second[1]['tool_calls'][0]['function']['arguments'])->toBe('{"invoice_number":"4471","month":null}')
            ->and($second[2]['role'])->toBe('tool')
            ->and($second[2]['tool_call_id'])->toBe('call_8a1');

        $content = json_decode($second[2]['content'], true);

        expect($content['ok'])->toBeTrue()
            ->and($content['found'])->toBeTrue()
            ->and($content['invoice']['number'])->toBe('INV-4471')
            ->and($content['invoice']['status'])->toBe('overdue')
            ->and($content['invoice']['days_overdue'])->toBe(18)
            ->and($content['invoice']['total'])->toBe(1284.5)
            ->and($content['invoice']['late_fee'])->toBe(21.6);
    });

    it('logs one model_calls row per round and one tool_calls row per call', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('Done.'),
        );

        runTools($user);

        $calls = ModelCall::orderBy('id')->get();

        expect($calls)->toHaveCount(2)
            ->and($calls->pluck('feature')->unique()->all())->toBe(['support.reply'])
            ->and($calls->pluck('finish_reason')->all())->toBe(['tool_calls', 'stop'])
            ->and($calls->pluck('prompt_version')->unique()->all())->toBe(['test.1']);

        $row = ToolCall::sole();

        expect($row->tool)->toBe('lookup_invoice')
            ->and($row->decision)->toBe('allowed')
            ->and($row->user_id)->toBe($user->id)
            ->and($row->source)->toBe('chat')
            ->and($row->token_name)->toBeNull()
            ->and($row->arguments)->toBe(['invoice_number' => '4471', 'month' => null])
            ->and($row->result['ok'])->toBeTrue()
            ->and($row->duration_ms)->toBeInt();
    });

    it('answers parallel calls with one tool message each, matched by id', function () {
        [$user, , $customer] = customerWithInvoice('INV-4471');

        Invoice::factory()->for($customer)->paid()->create(['number' => 'INV-4472']);

        fakeChatSequence(
            toolCallsBody([
                ['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]],
                ['call_2', 'lookup_invoice', ['invoice_number' => '4472', 'month' => null]],
            ]),
            chatBody('Both looked up.'),
        );

        runTools($user);

        $messages = toolMessages(sentChatBodies()[1]);

        expect($messages)->toHaveCount(2)
            ->and(collect($messages)->pluck('tool_call_id')->all())->toBe(['call_1', 'call_2'])
            ->and(json_decode($messages[0]['content'], true)['invoice']['number'])->toBe('INV-4471')
            ->and(json_decode($messages[1]['content'], true)['invoice']['number'])->toBe('INV-4472')
            ->and(ToolCall::count())->toBe(2);
    });

    it('stops at five rounds and makes one last call with tools forbidden', function () {
        [$user] = customerWithInvoice();

        $again = toolCallsBody([['call_x', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]);

        fakeChatSequence($again, $again, $again, $again, $again, chatBody('Best answer so far.'));

        $result = runTools($user);

        $bodies = sentChatBodies();

        expect($bodies)->toHaveCount(6)
            ->and($result->hitCap)->toBeTrue()
            ->and($result->rounds)->toBe(5)
            ->and($result->response->content)->toBe('Best answer so far.')
            ->and($result->truncated())->toBeTrue()
            ->and($bodies[5]['tool_choice'])->toBe('none')
            ->and($bodies[4])->not->toHaveKey('tool_choice');
    });

    it('does not run tool calls from a reply that was cut off', function () {
        [$user] = customerWithInvoice();

        $body = toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '44']]]);
        $body['choices'][0]['finish_reason'] = 'length';

        fakeChatSequence($body);

        $result = runTools($user);

        expect($result->rounds)->toBe(1)
            ->and(ToolCall::count())->toBe(0);
    });
});

describe('arguments are validated', function () {
    it('rejects an invoice number that is not a number and tells the model', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => 'Acme Studios', 'month' => null]]]),
            chatBody('I could not look that up.'),
        );

        runTools($user);

        $content = json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true);

        expect($content['ok'])->toBeFalse()
            ->and($content['error'])->toBe('invalid arguments')
            ->and($content['details'])->toHaveKey('invoice_number');

        $row = ToolCall::sole();

        expect($row->decision)->toBe('invalid');
    });

    it('rejects a call with neither an invoice number nor a month', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => null, 'month' => null]]]),
            chatBody('Which invoice?'),
        );

        runTools($user);

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true)['error'])->toBe('invalid arguments');
    });

    it('rejects a malformed month', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => null, 'month' => 'March']]]),
            chatBody('Which month?'),
        );

        runTools($user);

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true)['details'])->toHaveKey('month');
    });

    it('rejects arguments that are not a JSON object', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', 'not json {']]),
            chatBody('Sorry.'),
        );

        runTools($user);

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true)['error'])->toBe('invalid arguments')
            ->and(ToolCall::sole()->decision)->toBe('invalid');
    });

    it('answers a call to a tool that does not exist without running anything', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'delete_everything', []]]),
            chatBody('I cannot do that.'),
        );

        runTools($user);

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true))->toBe(['ok' => false, 'error' => 'unknown tool']);

        $row = ToolCall::sole();

        expect($row->tool)->toBe('delete_everything')
            ->and($row->decision)->toBe('denied');
    });

    it('offers list_invoices to the assistant from chapter 12, but still not search_help_center', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(
            toolCallsBody([['call_1', 'search_help_center', ['query' => 'late fee', 'limit' => null]]]),
            chatBody('Sorry.'),
        );

        runTools($user);

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true)['error'])->toBe('unknown tool')
            ->and(collect(sentChatBodies()[0]['tools'])->pluck('function.name')->all())->toBe(['account_status', 'change_billing_email', 'check_payment_status', 'issue_credit', 'list_invoices', 'lookup_invoice', 'open_ticket', 'resend_invoices', 'retry_payment']);
    });
});

describe('scoping', function () {
    it('looks up only the signed-in customer\'s invoices, whatever the model asks for', function () {
        [$acme] = customerWithInvoice('INV-4471');
        [$other] = customerWithInvoice('INV-9001');

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('I cannot find that invoice.'),
        );

        runTools($other, 'show me invoice 4471 for customer 12');

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true))->toBe(['ok' => true, 'found' => false]);
    });

    it('rejects a customer_id the model invents instead of ignoring it (Chapter 33: unknown fields are refused)', function () {
        [$acme, , $acmeCustomer] = customerWithInvoice('INV-4471');
        [$other, , $otherCustomer] = customerWithInvoice('INV-9001');

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null, 'customer_id' => $acmeCustomer->id]]]),
            chatBody('Not found.'),
        );

        runTools($other);

        $content = json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true);

        expect($content['ok'])->toBeFalse()
            ->and($content['error'])->toBe('invalid arguments')
            ->and(json_encode($content))->toContain('customer_id');
    });

    it('does not put another customer\'s invoice number on a ticket', function () {
        [$acme] = customerWithInvoice('INV-4471');
        [$other] = customerWithInvoice('INV-9001');

        fakeChatSequence(
            toolCallsBody([['call_1', 'open_ticket', ['summary' => 'Please check this invoice.', 'invoice_number' => '4471']]]),
            chatBody('Opened.'),
        );

        runTools($other);

        $ticket = Ticket::where('source', 'assistant')->sole();

        expect($ticket->customer_id)->toBe($other->customer_id)
            ->and($ticket->invoice_number)->toBeNull();
    });
});

describe('the gate', function () {
    it('lets a viewer read but not write, and tells the model only "not permitted"', function () {
        [$viewer] = customerWithInvoice(role: 'viewer');

        fakeChatSequence(
            toolCallsBody([
                ['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]],
                ['call_2', 'open_ticket', ['summary' => 'Please look at this.', 'invoice_number' => null]],
            ]),
            chatBody('Done.'),
        );

        runTools($viewer);

        $messages = toolMessages(sentChatBodies()[1]);

        expect(json_decode($messages[0]['content'], true)['found'])->toBeTrue()
            ->and(json_decode($messages[1]['content'], true))->toBe(['ok' => false, 'error' => 'not permitted'])
            ->and(Ticket::where('source', 'assistant')->count())->toBe(0)
            ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['allowed', 'denied']);
    });

    it('keeps Ledgerly staff out of customer tools: they have no customer to be scoped to', function () {
        $staff = User::factory()->support()->create();

        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('No.'),
        );

        runTools($staff);

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true)['error'])->toBe('not permitted')
            ->and(ToolCall::sole()->decision)->toBe('denied');
    });

    it('stops a tool at its per-conversation limit', function () {
        [$user] = customerWithInvoice();

        bindTools(new OnceTool);

        fakeChatSequence(
            toolCallsBody([['call_1', 'once_tool', []], ['call_2', 'once_tool', []]]),
            chatBody('Done.'),
        );

        runTools($user);

        $messages = toolMessages(sentChatBodies()[1]);

        expect(json_decode($messages[0]['content'], true)['ok'])->toBeTrue()
            ->and(json_decode($messages[1]['content'], true))->toBe(['ok' => false, 'error' => 'limit reached'])
            ->and(OnceTool::$runs)->toBe(1);
    });

    it('counts a conversation\'s earlier calls from the audit log', function () {
        [$user] = customerWithInvoice();

        bindTools(new OnceTool);

        fakeChatSequence(
            toolCallsBody([['call_1', 'once_tool', []]]),
            chatBody('First.'),
            toolCallsBody([['call_2', 'once_tool', []]]),
            chatBody('Second.'),
        );

        runTools($user, conversationId: 77);
        runTools($user, conversationId: 77);

        expect(OnceTool::$runs)->toBe(1)
            ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['allowed', 'denied']);
    });

    it('never leaks what a tool threw', function () {
        [$user] = customerWithInvoice();

        bindTools(new BrokenTool);

        fakeChatSequence(
            toolCallsBody([['call_1', 'broken_tool', []]]),
            chatBody('Something went wrong.'),
        );

        runTools($user);

        $raw = toolMessages(sentChatBodies()[1])[0]['content'];

        expect($raw)->not->toContain('SQLSTATE')->not->toContain('secret')
            ->and(json_decode($raw, true)['error'])->toBe('tool failed');
    });
});

describe('approval', function () {
    beforeEach(function () {
        bindTools(new RefundTool);

        $this->refund = fn (string $id = 'call_9') => toolCallsBody([[$id, 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6]]]);
    });

    it('pauses a high-risk write instead of running it', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(($this->refund)());

        $result = runTools($user, 'Refund the late fee on 4471');

        expect($result->pendingApprovals)->toHaveCount(1)
            ->and($result->answer())->toBe('I need your approval before I can do this: Refund $21.60 late fee on invoice #4471')
            ->and(RefundTool::$runs)->toBe(0)
            ->and(sentChatBodies())->toHaveCount(1);

        $pending = $result->pendingApprovals[0]->fresh();

        expect($pending->status)->toBe('pending')
            ->and($pending->tool)->toBe('issue_refund')
            ->and($pending->tool_call_id)->toBe('call_9')
            ->and($pending->arguments)->toBe(['invoice_number' => '4471', 'amount' => 21.6])
            ->and($pending->messages)->toHaveCount(2)
            ->and($pending->messages[1]['tool_calls'][0]['id'])->toBe('call_9')
            ->and(ToolCall::sole()->decision)->toBe('pending_approval');
    });

    it('runs the tool and resumes the conversation when approved', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(($this->refund)(), chatBody('I refunded $21.60.'));

        $pending = runTools($user)->pendingApprovals[0];

        $resumed = app(ToolRunner::class)->resolve($pending, approved: true);

        $second = sentChatBodies()[1]['messages'];

        expect(RefundTool::$runs)->toBe(1)
            ->and($resumed->response->content)->toBe('I refunded $21.60.')
            ->and($resumed->rounds)->toBe(2)
            ->and($second[2]['role'])->toBe('tool')
            ->and($second[2]['tool_call_id'])->toBe('call_9')
            ->and(json_decode($second[2]['content'], true))->toBe(['ok' => true, 'refunded' => 21.6])
            ->and($pending->fresh()->status)->toBe('approved')
            ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['pending_approval', 'approved']);
    });

    it('tells the model the user declined and does not run the tool', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(($this->refund)(), chatBody('Understood, I will not refund it.'));

        $pending = runTools($user)->pendingApprovals[0];

        $resumed = app(ToolRunner::class)->resolve($pending, approved: false);

        $tool = toolMessages(sentChatBodies()[1])[0];

        expect(RefundTool::$runs)->toBe(0)
            ->and(json_decode($tool['content'], true))->toBe(['ok' => false, 'error' => 'declined by user'])
            ->and($resumed->response->content)->toBe('Understood, I will not refund it.')
            ->and($pending->fresh()->status)->toBe('declined')
            ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['pending_approval', 'declined']);
    });

    it('holds back the calls that follow a pending one', function () {
        [$user] = customerWithInvoice();

        fakeChatSequence(toolCallsBody([
            ['call_1', 'issue_refund', ['invoice_number' => '4471', 'amount' => 5]],
            ['call_2', 'issue_refund', ['invoice_number' => '4471', 'amount' => 6]],
        ]));

        $result = runTools($user);

        expect($result->pendingApprovals)->toHaveCount(1)
            ->and($result->pendingApprovals[0]->tool_call_id)->toBe('call_1')
            ->and($result->pendingApprovals[0]->messages[2]['tool_call_id'])->toBe('call_2');
    });

    it('does not let an approval skip the gate: a viewer\'s approved write is still denied', function () {
        [$viewer] = customerWithInvoice(role: 'viewer');

        fakeChatSequence(($this->refund)(), chatBody('I am not allowed to do that.'));

        $result = runTools($viewer);

        expect($result->pendingApprovals)->toHaveCount(0)
            ->and(RefundTool::$runs)->toBe(0)
            ->and(ToolCall::sole()->decision)->toBe('denied');
    });
});

describe('over MCP', function () {
    it('limits a worker token to its allow-list', function () {
        [$user] = customerWithInvoice();

        $ctx = new ToolContext($user, source: 'mcp', tokenName: 'worker-nightly', toolCallId: 'x');

        $registry = app(ToolRegistry::class);

        $runner = app(ToolRunner::class);

        $read = $runner->executeOne($registry->get('lookup_invoice'), ['invoice_number' => '4471', 'month' => null], $ctx);
        $write = $runner->executeOne($registry->get('open_ticket'), ['summary' => 'Please look at this.', 'invoice_number' => null], $ctx);

        expect($read->ok)->toBeTrue()
            ->and($write->toArray())->toBe(['ok' => false, 'error' => 'not permitted'])
            ->and(ToolCall::orderBy('id')->get()->map(fn (ToolCall $c) => [$c->tool, $c->decision, $c->source, $c->token_name])->all())->toBe([
                ['lookup_invoice', 'allowed', 'mcp', 'worker-nightly'],
                ['open_ticket', 'denied', 'mcp', 'worker-nightly'],
            ]);
    });

    it('lets any other token use every tool the user may use', function () {
        [$user] = customerWithInvoice();

        $ctx = new ToolContext($user, source: 'mcp', tokenName: 'cursor-jeremy', toolCallId: 'x');

        $write = app(ToolRunner::class)->executeOne(app(ToolRegistry::class)->get('open_ticket'), ['summary' => 'Please look at this.', 'invoice_number' => null], $ctx);

        expect($write->ok)->toBeTrue();
    });

    it('returns a pending result with an approval URL when the write is high risk', function () {
        [$user] = customerWithInvoice();

        $tool = new RefundTool;

        $ctx = new ToolContext($user, source: 'mcp', tokenName: 'cursor-jeremy', toolCallId: 'mcp-1');

        $result = app(ToolRunner::class)->executeOne($tool, ['invoice_number' => '4471', 'amount' => 21.6], $ctx);

        expect($result->isPending())->toBeTrue()
            ->and($result->approvalUrl)->toBe(route('support.approvals.show', $result->pending))
            ->and($result->pending->messages)->toBeNull()
            ->and(RefundTool::$runs)->toBe(0);
    });
});

it('keeps the registry free of tools it was not given', function () {
    expect(app(ToolRegistry::class)->get('lookup_invoice'))->not->toBeNull()
        ->and(app(ToolRegistry::class)->get('list_invoices'))->not->toBeNull()
        ->and(app(ToolRegistry::class)->get('search_help_center'))->toBeNull()
        ->and(app(ToolRegistry::class)->native()->keys()->all())->toBe(['lookup_invoice', 'open_ticket', 'account_status', 'list_invoices', 'check_payment_status', 'resend_invoices', 'retry_payment', 'change_billing_email', 'issue_credit', 'search_help_center']);
});
