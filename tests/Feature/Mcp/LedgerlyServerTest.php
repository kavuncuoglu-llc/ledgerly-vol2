<?php

use App\Ai\Tools\ToolRegistry;
use App\Events\TicketReceived;
use App\Mcp\McpToolAdapter;
use App\Models\PendingToolCall;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Tests\Support\RefundTool;

function mcp(string $token, string $method, array $params = [], int $id = 1): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('mcp/ledgerly', [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params === [] ? (object) [] : $params,
    ], [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2025-11-25',
    ]);
}

function callTool(string $token, string $tool, array $arguments): TestResponse
{
    return mcp($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
}

function toolText(TestResponse $response): string
{
    return $response->json('result.content.0.text');
}

function streamedResult(TestResponse $response): array
{
    $data = json_decode(substr(trim($response->streamedContent()), strlen('data: ')), true);

    return $data['result'];
}

beforeEach(function () {
    Event::fake([TicketReceived::class]);

    $this->seed();

    $this->maya = User::find(12);
    $this->token = $this->maya->createToken('cursor-jeremy')->plainTextToken;
});

describe('authentication', function () {
    it('turns away a request without a token', function () {
        $this->postJson('mcp/ledgerly', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->assertUnauthorized();
    });

    it('turns away a token that does not exist', function () {
        mcp('1|not-a-real-token', 'tools/list')->assertUnauthorized();
    });

    it('stops working the moment the token is revoked', function () {
        mcp($this->token, 'tools/list')->assertOk();

        $this->maya->tokens()->delete();

        mcp($this->token, 'tools/list')->assertUnauthorized();
    });

    it('does not answer GET', function () {
        $this->get('mcp/ledgerly')->assertStatus(405);
    });
});

describe('the server', function () {
    it('introduces itself', function () {
        $result = mcp($this->token, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'cursor', 'version' => '1']])
            ->assertOk()
            ->json('result');

        expect($result['serverInfo']['name'])->toBe('Ledgerly')
            ->and($result['instructions'])->toContain('Writes require approval in the Ledgerly app')
            ->and($result['capabilities'])->toHaveKeys(['tools', 'resources', 'prompts']);
    });

    it('lists the registry\'s native tools with their schemas and risk hints', function () {
        $tools = collect(mcp($this->token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        expect($tools->keys()->all())->toBe(['lookup_invoice', 'open_ticket', 'account_status', 'look_up_plan_change', 'list_invoices', 'check_payment_status', 'calculate_proration', 'resend_invoices', 'retry_payment', 'change_billing_email', 'issue_credit', 'search_help_center'])
            ->and($tools['lookup_invoice']['inputSchema']['required'])->toBe(['invoice_number', 'month'])
            ->and($tools['lookup_invoice']['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($tools['lookup_invoice']['annotations']['readOnlyHint'])->toBeTrue()
            ->and($tools['open_ticket']['annotations']['readOnlyHint'])->toBeFalse()
            ->and($tools['list_invoices']['description'])->toContain('Do not use for one specific invoice');
    });
});

describe('calling tools', function () {
    it('answers "which of Acme\'s invoices are overdue?" and audits the call with source mcp and the token name', function () {
        $response = callTool($this->token, 'list_invoices', ['status' => 'overdue', 'months' => null])->assertOk();

        $text = toolText($response);

        expect($response->json('result.isError'))->toBeFalse()
            ->and($text)->toContain('invoices[2]{number,status,due,total,late_fee,days_overdue}:')
            ->and($text)->toContain('  INV-4471,overdue,2026-04-02,1284.5,21.6,18')
            ->and($text)->toContain('  INV-4438,overdue,2026-03-10,820,35.2,41');

        $row = ToolCall::sole();

        expect([$row->tool, $row->source, $row->token_name, $row->decision, $row->user_id])->toBe(['list_invoices', 'mcp', 'cursor-jeremy', 'allowed', 12]);
    });

    it('scopes every call to the token\'s user', function () {
        $other = User::find(13)->createToken('cursor-other')->plainTextToken;

        $data = json_decode(toolText(callTool($other, 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])->assertOk()), true);

        expect($data)->toBe(['ok' => true, 'found' => false]);
    });

    it('validates arguments and reports the error as a tool error, not a crash', function () {
        $response = callTool($this->token, 'lookup_invoice', ['invoice_number' => 'Acme', 'month' => null])->assertOk();

        expect($response->json('result.isError'))->toBeTrue()
            ->and(json_decode(toolText($response), true)['error'])->toBe('invalid arguments');
    });

    it('lets a low-risk write through and creates the real ticket', function () {
        $response = callTool($this->token, 'open_ticket', ['summary' => 'Editor asked me to log this.', 'invoice_number' => '4471'])->assertOk();

        expect(json_decode(toolText($response), true))->toMatchArray(['ok' => true, 'ticket_number' => 'T-10431', 'created' => true])
            ->and(Ticket::find(10431)->source)->toBe('assistant');
    });

    it('keeps a worker token to its allow-list: it can read invoices and cannot open tickets', function () {
        $worker = $this->maya->createToken('worker-nightly')->plainTextToken;

        $read = callTool($worker, 'list_invoices', ['status' => 'overdue', 'months' => null]);
        $write = callTool($worker, 'open_ticket', ['summary' => 'Automation tried to log this.', 'invoice_number' => null]);

        expect($read->json('result.isError'))->toBeFalse()
            ->and($write->json('result.isError'))->toBeTrue()
            ->and(json_decode(toolText($write), true))->toBe(['ok' => false, 'error' => 'not permitted'])
            ->and(Ticket::where('source', 'assistant')->count())->toBe(0)
            ->and(ToolCall::orderBy('id')->get()->map(fn ($c) => [$c->tool, $c->decision, $c->token_name])->all())->toBe([
                ['list_invoices', 'allowed', 'worker-nightly'],
                ['open_ticket', 'denied', 'worker-nightly'],
            ]);
    });

    it('answers a high-risk write with an approval URL instead of running it', function () {
        RefundTool::$runs = 0;

        bindTools(new RefundTool);

        $response = callTool($this->token, 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6])->assertOk();

        $pending = PendingToolCall::sole();

        expect(toolText($response))->toBe('Awaiting approval in Ledgerly: '.route('support.approvals.show', $pending))
            ->and(RefundTool::$runs)->toBe(0)
            ->and([$pending->source, $pending->token_name, $pending->status, $pending->messages])->toBe(['mcp', 'cursor-jeremy', 'pending', null])
            ->and(ToolCall::sole()->decision)->toBe('pending_approval');
    });

    it('resumes nothing but runs the tool when the human approves an MCP request', function () {
        RefundTool::$runs = 0;

        bindTools(new RefundTool);

        callTool($this->token, 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6])->assertOk();

        $pending = PendingToolCall::sole();

        $this->actingAs($this->maya)
            ->postJson(route('support.approve', $pending), ['token' => approvalToken($pending)])
            ->assertOk()
            ->assertJson(['status' => 'approved', 'answer' => 'Done.']);

        expect(RefundTool::$runs)->toBe(1)
            ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['pending_approval', 'approved']);
    });

    it('refuses a token whose user has no customer', function () {
        $staff = User::where('role', 'support')->first()->createToken('cursor-staff')->plainTextToken;

        $response = callTool($staff, 'list_invoices', ['status' => null, 'months' => null])->assertOk();

        expect($response->json('result.isError'))->toBeTrue()
            ->and(json_decode(toolText($response), true)['error'])->toBe('not permitted');
    });
});

describe('resources', function () {
    it('lists the two resource templates', function () {
        $templates = collect(mcp($this->token, 'resources/templates/list')->assertOk()->json('result.resourceTemplates'));

        expect($templates->pluck('uriTemplate')->sort()->values()->all())->toBe(['help://articles/{category}/{slug}', 'ledgerly://customers/{id}/invoices']);
    });

    it('reads a help article as Markdown', function () {
        $response = mcp($this->token, 'resources/read', ['uri' => 'help://articles/billing/late-fees'])->assertOk();

        expect($response->json('result.contents.0.text'))->toContain('# ')
            ->and($response->json('result.contents.0.uri'))->toBe('help://articles/billing/late-fees');
    });

    it('says so for an article that does not exist', function () {
        $response = mcp($this->token, 'resources/read', ['uri' => 'help://articles/billing/no-such-article']);

        expect($response->json('error.message'))->toBe('No such help article.');
    });

    it('reads the token owner\'s own invoice list', function () {
        $response = mcp($this->token, 'resources/read', ['uri' => 'ledgerly://customers/12/invoices'])->assertOk();

        $text = $response->json('result.contents.0.text');

        expect($text)->toContain('invoices[')
            ->and($text)->toContain('INV-4471,overdue,2026-04-02,1284.5,21.6,18');
    });

    it('does not read another customer\'s invoice list', function () {
        $response = mcp($this->token, 'resources/read', ['uri' => 'ledgerly://customers/13/invoices']);

        expect($response->json('error.message'))->toBe('No such customer.')
            ->and($response->getContent())->not->toContain('INV-45');
    });
});

describe('prompts', function () {
    it('lists the app\'s own prompts', function () {
        $names = collect(mcp($this->token, 'prompts/list')->assertOk()->json('result.prompts'))->pluck('name')->sort()->values()->all();

        expect($names)->toBe(['support-prompt', 'ticket-classify-prompt']);
    });

    it('serves SupportPrompt with the question filled in', function () {
        $messages = streamedResult(mcp($this->token, 'prompts/get', ['name' => 'support-prompt', 'arguments' => ['question' => 'What is a late fee?']]))['messages'];

        expect($messages[0]['content']['text'])->toContain('You are the support assistant for Ledgerly')
            ->and(collect($messages)->pluck('content.text')->join("\n"))->toContain('What is a late fee?')->toContain('untrusted-');
    });

    it('serves TicketClassifyPrompt', function () {
        $messages = streamedResult(mcp($this->token, 'prompts/get', ['name' => 'ticket-classify-prompt', 'arguments' => ['message' => 'Export is broken']]))['messages'];

        expect($messages[0]['content']['text'])->toContain('You classify Ledgerly support messages')
            ->and($messages[1]['content']['text'])->toBe('Export is broken');
    });
});

describe('stdio, where there is no login', function () {
    it('acts as the configured local user', function () {
        config(['ai.mcp.local_user' => 12]);

        $adapter = new McpToolAdapter(app(ToolRegistry::class)->native()->get('lookup_invoice'));

        $response = $adapter->handle(new Request(['invoice_number' => '4471', 'month' => null]));

        $row = ToolCall::sole();

        expect($response->isError())->toBeFalse()
            ->and([$row->user_id, $row->source, $row->token_name])->toBe([12, 'mcp', null]);
    });

    it('refuses to act as anyone when no local user is configured', function () {
        config(['ai.mcp.local_user' => null]);

        $adapter = new McpToolAdapter(app(ToolRegistry::class)->native()->get('lookup_invoice'));

        $response = $adapter->handle(new Request(['invoice_number' => '4471', 'month' => null]));

        expect($response->isError())->toBeTrue()
            ->and(ToolCall::count())->toBe(0);
    });
});
