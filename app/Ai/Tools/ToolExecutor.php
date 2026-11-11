<?php

namespace App\Ai\Tools;

use App\Ai\Events\ToolCalled;
use App\Ai\Tracing\Tracing;
use App\Models\PendingToolCall;
use Closure;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

final class ToolExecutor
{
    public function __construct(private ToolRegistry $tools, private ToolGate $gate) {}

    public function executeOne(Tool $tool, array $args, ToolContext $ctx, bool $approved = false): ToolResult
    {
        return $this->traced($tool->name(), fn () => $this->executeUntraced($tool, $args, $ctx, $approved));
    }

    private function executeUntraced(Tool $tool, array $args, ToolContext $ctx, bool $approved): ToolResult
    {
        return $this->gate->dispatch($tool, $args, $ctx, $approved);                  // Chapter 33: schema, policy, budget, execution by risk, scan, audit: one path
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
        Span::getCurrent()->setAttribute('ledgerly.tool.decision', $decision);        // the gate's decision on the span, never the arguments

        ToolCalled::dispatch($tool->name(), $args, $result, $ctx, $decision, (int) ((hrtime(true) - $started) / 1_000_000), ...array_values(Tracing::ids()));

        return $result;
    }

    // Chapter 31, Topic 5: one span per tool execution, between the model call that asked for it and the one that uses the result, with the tool's name and the gate's decision. The arguments and the result stay in tool_calls.
    private function traced(string $tool, Closure $work): ToolResult
    {
        $span = Tracing::tracer()->spanBuilder("execute_tool {$tool}")->setSpanKind(SpanKind::KIND_INTERNAL)->startSpan();

        $scope = $span->activate();

        $span->setAttributes(['gen_ai.operation.name' => 'execute_tool', 'gen_ai.tool.name' => $tool]);

        try {
            return $work();
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, class_basename($e));

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function auditUnknown(string $name, ToolContext $ctx): ToolResult
    {
        $result = ToolResult::error('unknown tool');

        $this->traced($name, function () use ($name, $result, $ctx) {
            Span::getCurrent()->setAttribute('ledgerly.tool.decision', 'denied');

            ToolCalled::dispatch($name, [], $result, $ctx, 'denied', 0, ...array_values(Tracing::ids()));

            return $result;
        });

        return $result;
    }
}
