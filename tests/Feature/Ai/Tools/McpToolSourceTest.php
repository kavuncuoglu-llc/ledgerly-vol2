<?php

use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\Mcp\LaravelMcpClient;
use App\Ai\Tools\Mcp\McpToolSource;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Models\ToolCall;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeMcpClient;

function postgresDefinition(): array
{
    return [
        'name' => 'query',
        'description' => 'Run a read-only SQL query.',
        'inputSchema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sql'],
            'properties' => ['sql' => ['type' => 'string', 'description' => 'The SQL.']],
        ],
    ];
}

function registryWith(FakeMcpClient $client, array $kinds = ['query' => 'read']): ToolRegistry
{
    $registry = new ToolRegistry;

    $registry->addSource(new McpToolSource($client, toolKinds: $kinds));

    app()->instance(ToolRegistry::class, $registry);

    return $registry;
}

function mcpCtx(): ToolContext
{
    [$user] = customerWithInvoice();

    return new ToolContext($user, toolCallId: 'call_1');
}

describe('McpToolSource', function () {
    it('turns each remote tool into a Tool with its own schema', function () {
        $tools = (new McpToolSource(new FakeMcpClient([postgresDefinition()])))->tools();

        expect($tools)->toHaveCount(1)
            ->and($tools[0]->name())->toBe('query')
            ->and($tools[0]->description())->toBe('Run a read-only SQL query.')
            ->and($tools[0]->inputSchema()['required'])->toBe(['sql']);
    });

    it('treats a tool as a high-risk write unless config declares it', function () {
        $client = new FakeMcpClient([postgresDefinition(), ['name' => 'drop', 'description' => '', 'inputSchema' => []], ['name' => 'note', 'description' => '', 'inputSchema' => []], ['name' => 'save', 'description' => '', 'inputSchema' => []]]);

        $kinds = collect((new McpToolSource($client, toolKinds: ['query' => 'read', 'note' => 'write_low_risk', 'save' => 'write']))->tools())
            ->mapWithKeys(fn ($tool) => [$tool->name() => $tool->kind()]);

        expect($kinds['query'])->toBe(ToolKind::Read)
            ->and($kinds['note'])->toBe(ToolKind::WriteLowRisk)
            ->and($kinds['save'])->toBe(ToolKind::WriteHighRisk)
            ->and($kinds['drop'])->toBe(ToolKind::WriteHighRisk);
    });
});

describe('a registry with an MCP source', function () {
    it('offers the remote tool to the model next to the native ones', function () {
        $registry = registryWith(new FakeMcpClient([postgresDefinition()]));

        $registry->add(new LookupInvoice);

        $definitions = collect($registry->definitions())->keyBy('function.name');

        expect($definitions->keys()->all())->toBe(['lookup_invoice', 'query'])
            ->and($definitions['query']['function']['parameters']['required'])->toBe(['sql']);
    });

    it('does not re-export third-party tools when Ledgerly is the MCP server', function () {
        $registry = registryWith(new FakeMcpClient([postgresDefinition()]));

        expect($registry->get('query'))->not->toBeNull()
            ->and($registry->native()->keys()->all())->toBe([]);
    });

    it('keeps working when a server is down', function () {
        Log::spy();

        $registry = registryWith(new FakeMcpClient(down: true));

        $registry->add(new LookupInvoice);

        expect(collect($registry->definitions())->pluck('function.name')->all())->toBe(['lookup_invoice']);

        Log::shouldHaveReceived('warning')->once();
    });
});

describe('running a remote tool', function () {
    it('validates arguments against the remote schema, gates, calls and audits like a native tool', function () {
        $client = new FakeMcpClient([postgresDefinition()], ['isError' => false, 'text' => '[{"count":3}]']);

        registryWith($client);

        $result = app(ToolRunner::class)->executeOne(app(ToolRegistry::class)->get('query'), ['sql' => 'select count(*) from invoices'], mcpCtx());

        expect($result->ok)->toBeTrue()
            ->and($result->toJson())->toBe('[{"count":3}]')
            ->and($client->calls)->toBe([['query', ['sql' => 'select count(*) from invoices']]])
            ->and(ToolCall::sole()->decision)->toBe('allowed');
    });

    it('rejects a missing, mistyped or extra argument without calling the server', function (array $args) {
        $client = new FakeMcpClient([postgresDefinition()]);

        registryWith($client);

        $result = app(ToolRunner::class)->executeOne(app(ToolRegistry::class)->get('query'), $args, mcpCtx());

        expect($result->error)->toBe('invalid arguments')
            ->and($client->calls)->toBe([])
            ->and(ToolCall::sole()->decision)->toBe('invalid');
    })->with([
        'missing' => [[]],
        'wrong type' => [['sql' => 42]],
        'extra key' => [['sql' => 'select 1', 'database' => 'prod']],
    ]);

    it('pauses a tool the config did not declare as read-only', function () {
        $client = new FakeMcpClient([postgresDefinition()]);

        registryWith($client, kinds: []);

        $result = app(ToolRunner::class)->executeOne(app(ToolRegistry::class)->get('query'), ['sql' => 'delete from invoices'], mcpCtx());

        expect($result->isPending())->toBeTrue()
            ->and($client->calls)->toBe([]);
    });

    it('reports a failing remote tool without pretending it worked', function () {
        $client = new FakeMcpClient([postgresDefinition()], ['isError' => true, 'text' => 'relation does not exist']);

        registryWith($client);

        $result = app(ToolRunner::class)->executeOne(app(ToolRegistry::class)->get('query'), ['sql' => 'select * from nope'], mcpCtx());

        expect($result->toArray())->toBe(['ok' => false, 'error' => 'tool failed', 'details' => ['message' => 'relation does not exist']]);
    });
});

describe('LaravelMcpClient', function () {
    it('builds a stdio client from config without starting the process', function () {
        expect(LaravelMcpClient::fromConfig(['transport' => 'stdio', 'command' => ['php', '-v'], 'env' => []]))->toBeInstanceOf(LaravelMcpClient::class);
    });

    it('builds an HTTP client from config', function () {
        expect(LaravelMcpClient::fromConfig(['transport' => 'http', 'url' => 'https://mcp.example.com/mcp', 'token' => 'secret']))->toBeInstanceOf(LaravelMcpClient::class);
    });

    it('refuses a transport it does not know', function () {
        LaravelMcpClient::fromConfig(['transport' => 'carrier-pigeon']);
    })->throws(InvalidArgumentException::class, 'Unknown MCP transport');
});
