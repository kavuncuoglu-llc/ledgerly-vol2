<?php

namespace App\Ai\Tools\Mcp;

interface McpClient
{
    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public function listTools(): array;

    /** @return array{isError: bool, text: string} */
    public function callTool(string $name, array $arguments): array;
}
