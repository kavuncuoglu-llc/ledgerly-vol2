<?php

namespace App\Ai\Tools\Mcp;

use InvalidArgumentException;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Primitives\Tool as RemoteTool;

final class LaravelMcpClient implements McpClient
{
    public function __construct(private Client $client) {}

    public static function fromConfig(array $server): self
    {
        $transport = $server['transport'] ?? 'stdio';

        if ($transport === 'http') {
            $client = Client::web($server['url'])->withHeaders($server['headers'] ?? []);

            if (! empty($server['token'])) {
                $client->withToken($server['token']);
            }

            return new self($client);
        }

        if ($transport !== 'stdio') {
            throw new InvalidArgumentException("Unknown MCP transport '{$transport}'.");
        }

        $command = $server['command'] ?? [];

        return new self(Client::local(array_shift($command), $command, $server['env'] ?? []));
    }

    public function listTools(): array
    {
        return $this->client->tools()
            ->map(fn (RemoteTool $tool) => [
                'name' => $tool->name,
                'description' => (string) $tool->description,
                'inputSchema' => $tool->inputSchema,
            ])
            ->values()
            ->all();
    }

    public function callTool(string $name, array $arguments): array
    {
        $result = $this->client->callTool($name, $arguments);

        return ['isError' => $result->isError, 'text' => $result->text()];
    }
}
