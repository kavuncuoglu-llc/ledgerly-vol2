<?php

namespace App\Ai\Tools;

use App\Ai\Events\ToolCalled;
use App\Ai\Security\ForeignIdentifierScan;
use App\Ai\Tracing\Tracing;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenTelemetry\API\Trace\Span;
use Throwable;

// Chapter 33, Topic 4: the one way to run a tool. Six stages, always in this order: schema, policy, budget, execution by declared risk, a scan of the result, the audit row. The first three can only refuse.
final class ToolGate
{
    public const READ_ROLE = 'ledgerly_tool_read';

    public const WRITE_ROLE = 'ledgerly_tool_write';

    public function __construct(private ForeignIdentifierScan $scanner) {}

    public function dispatch(Tool $tool, array $args, ToolContext $ctx, bool $approved = false): ToolResult
    {
        $started = hrtime(true);

        [$validated, $errors] = $this->validate($tool, $args);                                      // 1. strict schema; unknown fields rejected

        if ($errors !== []) {
            return $this->audit($tool, $args, ToolResult::error('invalid arguments', $errors), $ctx, 'invalid', $started);
        }

        $decision = $this->check($tool, $args, $ctx);                                               // 2. policy: the session's user and customer, never an ID the model wrote.  3. budget: this tool and this conversation

        if (! $decision->allowed && ! $decision->requiresApproval) {
            return $this->audit($tool, $args, ToolResult::denied($decision->reason), $ctx, 'denied', $started);
        }

        $result = match ($tool->risk()) {                                                           // 4. execution context by declared risk
            Risk::Read => $this->inTransaction($tool, $validated, $ctx, role: self::READ_ROLE, readOnly: true),
            Risk::LowWrite => $this->idempotent($ctx, $tool, $args, fn () => $this->inTransaction($tool, $validated, $ctx, role: self::WRITE_ROLE, readOnly: false)),
            Risk::HighWrite => $approved
                ? $this->inTransaction($tool, $validated, $ctx, role: self::WRITE_ROLE, readOnly: false)
                : $this->requestApproval($tool, $args, $ctx),
        };

        $outcome = $this->decisionOf($result, $approved);

        try {
            $this->assertNoForeignIdentifiers($tool, $result, $ctx);                                       // 5. last check before the model sees it
        } catch (ForeignIdentifiersInResult $e) {
            Log::warning('A tool result named another customer\'s invoice and was withheld', ['tool' => $tool->name(), 'user_id' => $ctx->user->id]);

            $result = ToolResult::error('withheld');

            $outcome = 'blocked';
        }

        return $this->audit($tool, $args, $result, $ctx, $outcome, $started);                       // 6. audit
    }

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

        if ($ctx->toolCallsInConversation() >= (int) config('ai.tools.conversation_budget', 8)) {
            return GateDecision::deny('limit reached');                                   // a looping or hijacked agent shows itself here
        }

        return match ($tool->kind()) {
            ToolKind::Read => GateDecision::allow(),
            ToolKind::WriteLowRisk => GateDecision::allow(audit: true),
            ToolKind::WriteHighRisk => GateDecision::requireApproval(                     // a human says yes first
                summary: $tool->describe($args, $ctx),
            ),
        };
    }

    private function validate(Tool $tool, array $args): array
    {
        $declared = $tool instanceof RawSchemaTool
            ? array_keys((array) ($tool->inputSchema()['properties'] ?? []))
            : $this->declaredFields($tool);

        $unknown = array_values(array_diff(array_keys($args), $declared));

        if ($unknown !== []) {
            return [[], ['arguments' => ['Unknown fields: '.implode(', ', $unknown).'.']]];      // rejected, not ignored
        }

        if ($tool instanceof RawSchemaTool) {
            $errors = RawSchemaValidator::errors($tool->inputSchema(), $args);

            return [$errors === [] ? $args : [], $errors];
        }

        $validator = Validator::make($args, ($tool->arguments())::rules());

        return $validator->fails()
            ? [[], $validator->errors()->toArray()]
            : [$validator->validated(), []];
    }

    private function declaredFields(Tool $tool): array
    {
        return collect(array_keys(($tool->arguments())::rules()))
            ->map(fn (string $rule) => explode('.', $rule)[0])
            ->unique()
            ->values()
            ->all();
    }

    private function inTransaction(Tool $tool, array $validated, ToolContext $ctx, string $role, bool $readOnly): ToolResult
    {
        if ($tool instanceof RawSchemaTool) {
            return $this->guarded($tool, $validated, $ctx);                                          // an external server: no database of ours is involved
        }

        $ctx->user->loadMissing('customer');                                                        // read before the role is narrowed: the customer's row has columns no tool may see

        $nested = DB::transactionLevel() > 0;

        $tenant = DB::selectOne("select current_setting('app.tenant_id', true) as value")->value;

        DB::beginTransaction();

        try {
            if ($readOnly) {
                DB::statement('SET TRANSACTION READ ONLY');                                          // before anything else in the transaction
            }

            DB::statement("SET LOCAL ROLE {$role}");

            DB::statement('SELECT set_config(?, ?, true)', ['app.tenant_id', (string) $ctx->tenantId()]);   // row-level security, transaction-local

            $result = $this->run($tool, $validated, $ctx);                                           // tools select explicit columns

            $readOnly
                ? DB::rollBack()                                                                     // a read has nothing to keep, and rolling back puts the role and the flag back
                : DB::commit();

            return $result;
        } catch (Throwable $e) {
            DB::rollBack();                                                                          // a refused statement aborts the transaction: nothing the tool did stays

            report($e);

            return ToolResult::error('tool failed', ['message' => 'The lookup could not be completed.']);   // never leak internals
        } finally {
            if ($nested && ! $readOnly) {
                $this->restore($tenant);                                                             // inside a caller's transaction a committed SET LOCAL would outlive this call
            }
        }
    }

    private function restore(?string $tenant): void
    {
        DB::statement('RESET ROLE');

        DB::statement('SELECT set_config(?, ?, true)', ['app.tenant_id', (string) $tenant]);
    }

    private function run(Tool $tool, array $validated, ToolContext $ctx): ToolResult
    {
        $class = $tool->arguments();

        $result = $tool->handle($class::fromArray($validated), $ctx);

        $ctx->noteCall($tool->name());

        return $result;
    }

    private function guarded(Tool $tool, array $validated, ToolContext $ctx): ToolResult
    {
        try {
            return $this->run($tool, $validated, $ctx);
        } catch (Throwable $e) {
            report($e);

            return ToolResult::error('tool failed', ['message' => 'The lookup could not be completed.']);   // never leak internals
        }
    }

    // A retried job, a double-clicked card or a model that asks twice with the same call id does the write once.
    private function idempotent(ToolContext $ctx, Tool $tool, array $args, Closure $run): ToolResult
    {
        if ($tool instanceof IdempotentByItself) {
            return $run();                                                                           // it keys its own write in the database (open_ticket)
        }

        $key = 'tool-idempotency:'.hash('sha256', json_encode([$ctx->user->id, $ctx->conversationId, $ctx->toolCallId ?: json_encode($args), $tool->name()]));

        $stored = Cache::get($key);

        if (is_array($stored)) {
            return ToolResult::ok(array_diff_key($stored, ['ok' => true]));                           // what the first call returned
        }

        $result = $run();

        if ($result->ok) {
            Cache::put($key, $result->toArray(), now()->addDay());
        }

        return $result;
    }

    private function assertNoForeignIdentifiers(Tool $tool, ToolResult $result, ToolContext $ctx): void
    {
        if ($tool instanceof ReturnsSharedContent) {
            return;                                                                                  // help-center text is the same for everybody; an example invoice number in it is not a leak
        }

        if ($this->scanner->foreignInvoiceNumbers($result->toJson(), $ctx->customerId()) !== []) {
            throw new ForeignIdentifiersInResult;
        }
    }

    private function requestApproval(Tool $tool, array $args, ToolContext $ctx): ToolResult
    {
        $pending = PendingAction::request($tool, $args, $ctx);

        return ToolResult::pendingApproval($pending, route('support.approvals.show', $pending));
    }

    private function decisionOf(ToolResult $result, bool $approved): string
    {
        if ($result->isPending()) {
            return 'pending_approval';
        }

        return $approved
            ? 'approved'
            : 'allowed';
    }

    private function audit(Tool $tool, array $args, ToolResult $result, ToolContext $ctx, string $decision, int|float $started): ToolResult
    {
        Span::getCurrent()->setAttribute('ledgerly.tool.decision', $decision);                       // the gate's decision on the span, never the arguments

        ToolCalled::dispatch($tool->name(), $args, $result, $ctx, $decision, (int) ((hrtime(true) - $started) / 1_000_000), ...array_values(Tracing::ids()));

        return $result;
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
