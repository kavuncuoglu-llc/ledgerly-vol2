<?php

namespace App\Ai\Tools\Mcp;

use App\Ai\Tools\RawSchemaTool;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;

final class McpTool implements RawSchemaTool, Tool
{
    use ToolDefaults;

    public function __construct(private McpClient $client, private array $definition, private ToolKind $kind) {}

    public function name(): string
    {
        return $this->definition['name'];
    }

    public function description(): string
    {
        return $this->definition['description'] ?? '';
    }

    public function arguments(): string
    {
        return McpArguments::class;
    }

    public function inputSchema(): array
    {
        $schema = $this->definition['inputSchema'] ?? [];

        $schema['type'] ??= 'object';
        $schema['properties'] ??= (object) [];

        return $schema;
    }

    public function kind(): ToolKind
    {
        return $this->kind;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $result = $this->client->callTool($this->name(), $args->arguments);

        if ($result['isError']) {
            return ToolResult::error('tool failed', ['message' => $result['text']]);
        }

        return ToolResult::text($result['text']);
    }
}
