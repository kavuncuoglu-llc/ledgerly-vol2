<?php

namespace App\Ai\Agents;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolExecutor;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Models\AgentRun;
use Throwable;

// The Chapter 11 loop with a goal, state beyond the history, budgets and a trace. It runs inside the gate, the audit and the idempotency keys of the tools it calls.
final class AgentRunner
{
    public function __construct(private AiManager $ai, private ToolExecutor $executor) {}

    public function run(Agent $agent, AgentState $state, ToolContext $ctx, int $roundsAlreadyUsed = 0): AgentResult
    {
        $run = $agent->persists()
            ? $this->open($agent, $state, $ctx)
            : null;

        try {
            $result = $this->loop($agent, $state, $ctx, $agent->budget($roundsAlreadyUsed));
        } catch (Throwable $e) {
            $run?->update(['status' => 'failed', 'reason' => substr($e->getMessage(), 0, 40)]);

            throw $e;
        }

        if ($run === null) {
            return $result;
        }

        $this->close($run, $agent, $result);

        return $result->withRunId($run->id);
    }

    public function resume(Agent $agent, AgentState $state, ToolContext $ctx, int $roundsAlreadyUsed): AgentResult
    {
        return $this->run($agent, $state, $ctx, $roundsAlreadyUsed);               // the same loop, with the rounds already spent counted against the budget
    }

    private function loop(Agent $agent, AgentState $state, ToolContext $ctx, AgentBudget $budget): AgentResult
    {
        $trace = new Trace($agent->name(), $state->id);

        $trace->goal($state->goal);

        $tools = $agent->tools();

        $last = null;

        while ($budget->allows($state)) {
            $definitions = $tools->definitions();

            $response = $this->complete(
                $agent,
                $agent->prompt($state),
                $agent->options() + ($definitions === [] ? [] : ['tools' => $definitions]),   // an empty tools array is an error on some servers
            );

            $last = $response;

            $budget->recordRound($response->usage);

            $trace->decision($response);

            if ($response->toolCalls === [] || $response->finishReason === FinishReason::Length) {
                return AgentResult::done($state, $response, $trace, $budget);          // the model declared itself finished
            }

            $state = $agent->assistantTurn($state, $response);

            $pending = [];

            foreach ($response->toolCalls as $call) {
                $result = $pending === []
                    ? $this->executor->execute($call, $ctx, $tools)                   // gate, audit, idempotency: all Chapter 11
                    : ToolResult::error('not run', ['reason' => 'waiting for approval of an earlier call']);

                $trace->observation($call, $result);

                if ($result->isPending()) {
                    $pending[] = $result->pending;

                    continue;
                }

                $state = $agent->observe($state, $call, $result);                      // your code updates state, not just the history

                $budget->recordOutcome($call, $result);

                $budget->recordTool($tools->get((string) ($call['function']['name'] ?? ''))?->kind() ?? ToolKind::Read);
            }

            if ($pending !== []) {
                return AgentResult::paused($state, $response, $trace, $budget, $pending);
            }
        }

        $trace->note('budget', 'budget: '.$this->describe($budget->reason()).' → run ends (exhausted: '.$budget->reason().')');

        return AgentResult::exhausted($state, $trace, $budget, $last);                 // bounded: never loops forever
    }

    private function complete(Agent $agent, array $messages, array $options): ChatResponse
    {
        $listener = $agent instanceof StreamsReplies
            ? $agent->listener()
            : null;

        $provider = $options['provider'] ?? null;                                      // an escalation names its provider; everything else follows the feature map

        unset($options['provider']);

        $model = $provider === null
            ? $this->ai->for($agent->feature())
            : $this->ai->chat($provider);

        $request = ChatRequest::make($messages, $options, $agent->feature());

        if ($listener === null) {
            return $model->chat($request);
        }

        $stream = $model->stream($request);

        foreach ($stream as $event) {
            if ($event instanceof ToolCallStarted) {
                $listener->toolStarted($event->name);                                  // "looking up your invoice…" while the arguments are still arriving

                continue;
            }

            if ($event instanceof Delta) {
                $listener->delta($event->text);
            }
        }

        return $stream->getReturn();
    }

    private function describe(?string $reason): string
    {
        return match ($reason) {
            'rounds' => 'max_rounds reached',
            'tokens' => 'max_tokens reached',
            'time' => 'max_seconds reached',
            'writes' => 'max_writes reached',
            'no_progress' => 'two identical states in a row',
            'same_error' => 'the same tool error '.AgentBudget::MAX_SAME_ERROR.' times in a row',
            default => 'stopped',
        };
    }

    private function open(Agent $agent, AgentState $state, ToolContext $ctx): AgentRun
    {
        return AgentRun::create([
            'state_id' => $state->id,
            'agent' => $agent->name(),
            'feature' => $agent->feature(),
            'user_id' => $ctx->user->id,
            'tenant_id' => $ctx->user->tenant_id,
            'conversation_id' => $ctx->conversationId,
            'triage_run_id' => $ctx->triageRunId,
            'goal' => $state->goal,
            'status' => 'running',
        ]);
    }

    private function close(AgentRun $run, Agent $agent, AgentResult $result): void
    {
        $run->update([
            'status' => $result->status,
            'reason' => $result->reason,
            'rounds' => $result->rounds,
            'tokens' => $result->tokens,
            'writes' => $result->writes,
            'seconds' => round($result->seconds, 2),
            'state' => $result->state->toArray(withMessages: $agent->keepsMessages()),
            'trace' => $result->trace->toArray(),
        ]);
    }
}
