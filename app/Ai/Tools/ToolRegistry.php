<?php

namespace App\Ai\Tools;

use App\Ai\Schema\SchemaBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, Tool> */
    private array $mcpOnly = [];

    /** @var ToolSource[] */
    private array $sources = [];

    private ?array $fromSources = null;

    private ?array $allow = null;

    public function add(Tool $tool): self
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function addMcpOnly(Tool $tool): self
    {
        $this->mcpOnly[$tool->name()] = $tool;

        return $this;
    }

    public function addSource(ToolSource $source): self
    {
        $this->sources[] = $source;
        $this->fromSources = null;

        return $this;
    }

    public function get(string $name): ?Tool
    {
        return $this->available()[$name] ?? null;
    }

    /** @return Collection<string, Tool> everything the assistant may call */
    public function available(): Collection
    {
        $all = collect($this->tools + $this->sourced());

        return $this->allow === null
            ? $all
            : $all->only($this->allow);
    }

    /** @param string[] $names the only tools this copy offers and runs: the router's subset for one request */
    public function only(array $names): self
    {
        $copy = clone $this;

        $copy->allow = $names;

        return $copy;
    }

    /** @return Collection<string, Tool> the tools this application owns, which MCP clients also get */
    public function native(): Collection
    {
        return collect($this->tools + $this->mcpOnly);
    }

    public function definitions(): array
    {
        return $this->available()
            ->sortKeys()                                             // PHP arrays keep insertion order, but a prefix cache wants the same bytes every time: sort by name
            ->map(fn (Tool $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool instanceof RawSchemaTool
                        ? $tool->inputSchema()
                        : SchemaBuilder::for($tool->arguments()),
                ],
            ])
            ->values()
            ->all();
    }

    private function sourced(): array
    {
        if ($this->fromSources !== null) {
            return $this->fromSources;
        }

        $found = [];

        foreach ($this->sources as $source) {
            try {
                foreach ($source->tools() as $tool) {
                    $found[$tool->name()] ??= $tool;
                }
            } catch (Throwable $e) {
                Log::warning('A tool source could not be loaded', ['source' => $source::class, 'error' => $e->getMessage()]);
            }
        }

        return $this->fromSources = $found;
    }
}
