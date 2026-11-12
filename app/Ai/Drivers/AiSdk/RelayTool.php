<?php

namespace App\Ai\Drivers\AiSdk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

// Chapter 47, Topic 8: a tool the SDK describes to the model and never runs. Every call it makes needs approval, so the SDK's loop pauses on it and hands the call back, and the app's own tool loop dispatches it through ToolGate (Chapter 33). Permissions, confirmation tokens and the read-only database role therefore apply exactly as they do to the raw driver.
final class RelayTool implements Approvable, Tool
{
    public function __construct(private readonly array $definition) {}

    public function name(): string
    {
        return (string) $this->definition['name'];
    }

    public function description(): string
    {
        return (string) ($this->definition['description'] ?? '');
    }

    public function schema(JsonSchema $schema): array
    {
        return SdkSchema::properties($schema, $this->definition['parameters'] ?? []);
    }

    public function handle(Request $request): string
    {
        return '';
    }

    public function requireApproval(?string $reason = null): static
    {
        return $this;
    }

    public function withoutApproval(): static
    {
        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        return Approval::required('Dispatched by the application through ToolGate.');
    }
}
