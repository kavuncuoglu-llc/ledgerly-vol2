<?php

namespace App\Ai\Tools\Mcp;

use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolSource;

final class McpToolSource implements ToolSource
{
    public function __construct(
        private McpClient $client,
        private ToolKind $defaultKind = ToolKind::WriteHighRisk,
        private array $toolKinds = [],
    ) {}

    /** @return McpTool[] */
    public function tools(): array
    {
        return array_map(
            fn (array $def) => new McpTool($this->client, $def, $this->kindFor($def['name'])),   // kind from config: writes must be declared
            $this->client->listTools(),
        );
    }

    private function kindFor(string $tool): ToolKind
    {
        $declared = $this->toolKinds[$tool] ?? null;

        if ($declared === 'write') {
            return ToolKind::WriteHighRisk;
        }

        return ToolKind::tryFrom((string) $declared) ?? $this->defaultKind;
    }
}
