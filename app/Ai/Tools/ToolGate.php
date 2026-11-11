<?php

namespace App\Ai\Tools;

use Illuminate\Support\Facades\Gate;

final class ToolGate
{
    public function check(Tool $tool, array $args, ToolContext $ctx): GateDecision
    {
        if (! Gate::forUser($ctx->user)->allows('use-tool', $tool->name())) {
            return GateDecision::deny('not permitted');                                    // who may use it at all
        }

        if (! $this->tokenMayUse($tool, $ctx)) {
            return GateDecision::deny('not permitted');                                    // per-token allow-list over MCP
        }

        if ($ctx->toolCallsThisConversation($tool->name()) >= $tool->perConversationLimit()) {
            return GateDecision::deny('limit reached');                                   // scope and rate
        }

        return match ($tool->kind()) {
            ToolKind::Read => GateDecision::allow(),
            ToolKind::WriteLowRisk => GateDecision::allow(audit: true),
            ToolKind::WriteHighRisk => GateDecision::requireApproval(                     // a human says yes first
                summary: $tool->describeIntent($args, $ctx),
            ),
        };
    }

    private function tokenMayUse(Tool $tool, ToolContext $ctx): bool
    {
        if ($ctx->source !== 'mcp') {
            return true;
        }

        foreach (config('ai.mcp.allow_lists', []) as $pattern => $allowed) {
            if (fnmatch($pattern, (string) $ctx->tokenName)) {
                return in_array($tool->name(), $allowed, true);
            }
        }

        return true;
    }
}
