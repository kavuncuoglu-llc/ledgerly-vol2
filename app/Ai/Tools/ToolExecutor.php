<?php

namespace App\Ai\Tools;

use App\Ai\Events\ToolCalled;
use App\Models\PendingToolCall;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class ToolExecutor
{
    public function __construct(private ToolRegistry $tools, private ToolGate $gate) {}

    public function executeOne(Tool $tool, array $args, ToolContext $ctx, bool $approved = false): ToolResult
    {
        $started = hrtime(true);

        [$validated, $errors] = $this->validate($tool, $args);   // Chapter 10: enforced output still gets validated

        if ($errors !== []) {
            return $this->audit($tool, $args, ToolResult::error('invalid arguments', $errors), $ctx, 'invalid', $started);
        }

        $decision = $this->gate->check($tool, $args, $ctx);                    // Topic 5

        if ($decision->requiresApproval && ! $approved) {
            return $this->requestApproval($tool, $args, $ctx, $decision, $started);
        }

        if (! $decision->allowed && ! $decision->requiresApproval) {
            return $this->audit($tool, $args, ToolResult::denied($decision->reason), $ctx, 'denied', $started);
        }

        return $this->handle($tool, $validated, $args, $ctx, $approved ? 'approved' : 'allowed', $started);
    }

    private function validate(Tool $tool, array $args): array
    {
        if ($tool instanceof RawSchemaTool) {
            return [$args, RawSchemaValidator::errors($tool->inputSchema(), $args)];
        }

        $validator = Validator::make($args, ($tool->arguments())::rules());

        return $validator->fails()
            ? [[], $validator->errors()->toArray()]
            : [$validator->validated(), []];
    }

    public function execute(array $call, ToolContext $ctx, ?ToolRegistry $registry = null): ToolResult
    {
        $name = (string) ($call['function']['name'] ?? '');

        $callCtx = $ctx->withToolCallId((string) ($call['id'] ?? ''));

        $tool = ($registry ?? $this->tools)->get($name);

        if (! $tool) {
            return $this->auditUnknown($name, $callCtx);
        }

        $args = $this->decodeArguments($call['function']['arguments'] ?? '');

        if ($args === null) {
            return $this->audit($tool, [], ToolResult::error('invalid arguments', ['arguments' => ['The arguments were not a JSON object.']]), $callCtx, 'invalid', hrtime(true));
        }

        return $this->executeOne($tool, $args, $callCtx);
    }

    private function decodeArguments(mixed $arguments): ?array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        $decoded = json_decode((string) $arguments, true);

        return is_array($decoded)
            ? $decoded
            : null;
    }

    private function handle(Tool $tool, array $validated, array $args, ToolContext $ctx, string $decision, int|float $started): ToolResult
    {
        try {
            $class = $tool->arguments();

            $result = $tool->handle($class::fromArray($validated), $ctx);

            $ctx->noteCall($tool->name());
        } catch (Throwable $e) {
            report($e);

            $result = ToolResult::error('tool failed', ['message' => 'The lookup could not be completed.']);   // never leak internals
        }

        return $this->audit($tool, $args, $result, $ctx, $decision, $started);
    }

    private function requestApproval(Tool $tool, array $args, ToolContext $ctx, GateDecision $decision, int|float $started): ToolResult
    {
        $pending = PendingToolCall::create([
            'user_id' => $ctx->user->id,
            'conversation_id' => $ctx->conversationId,
            'tool' => $tool->name(),
            'tool_call_id' => $ctx->toolCallId ?? '',
            'arguments' => $args,
            'summary' => $decision->summary,
            'source' => $ctx->source,
            'token_name' => $ctx->tokenName,
        ]);

        $result = ToolResult::pendingApproval($pending, route('support.approvals.show', $pending));

        return $this->audit($tool, $args, $result, $ctx, 'pending_approval', $started);
    }

    public function settle(PendingToolCall $pending, bool $approved, ToolContext $ctx): ToolResult
    {
        $tool = $this->tools->get($pending->tool) ?? $this->tools->native()->get($pending->tool);

        if (! $tool) {
            return $this->auditUnknown($pending->tool, $ctx);
        }

        if (! $approved) {
            return $this->audit($tool, $pending->arguments, ToolResult::declined(), $ctx, 'declined', hrtime(true));
        }

        return $this->executeOne($tool, $pending->arguments, $ctx, approved: true);
    }

    private function audit(Tool $tool, array $args, ToolResult $result, ToolContext $ctx, string $decision, int|float $started): ToolResult
    {
        ToolCalled::dispatch($tool->name(), $args, $result, $ctx, $decision, (int) ((hrtime(true) - $started) / 1_000_000));

        return $result;
    }

    private function auditUnknown(string $name, ToolContext $ctx): ToolResult
    {
        $result = ToolResult::error('unknown tool');

        ToolCalled::dispatch($name, [], $result, $ctx, 'denied', 0);

        return $result;
    }
}
