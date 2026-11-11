<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AgentResult;
use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\ChatAgent;
use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Streaming\StreamListener;
use App\Models\PendingToolCall;
use App\Models\User;
use Illuminate\Support\Arr;

// Chapters 11 to 16 call this. Since Chapter 17 the loop itself is AgentRunner; this class keeps the old contract (messages in, ToolRunResult out) and the approval hand-off.
final class ToolRunner
{
    public function __construct(
        private AgentRunner $agents,
        private ToolExecutor $executor,
        private ToolRegistry $tools,
        private AiManager $ai,
    ) {}

    public function run(array $messages, ToolContext $ctx, array $options = [], string $feature = 'tools', ?ToolRegistry $tools = null, ?StreamListener $stream = null): ToolRunResult
    {
        return $this->loop($messages, $ctx, $options, $feature, 1, $tools ?? $this->tools, $stream);
    }

    public function resolve(PendingToolCall $pending, bool $approved, ?StreamListener $stream = null): ToolRunResult
    {
        $ctx = new ToolContext(
            user: User::findOrFail($pending->user_id),
            conversationId: $pending->conversation_id,
            source: $pending->source,
            tokenName: $pending->token_name,
            toolCallId: $pending->tool_call_id,
        );

        $result = $this->executor->settle($pending, $approved, $ctx);

        $pending->update(['status' => $approved ? 'approved' : 'declined']);

        if ($pending->messages === null) {
            return new ToolRunResult(response: null, messages: [], rounds: 0, toolResult: $result);
        }

        $messages = $pending->messages;
        $messages[] = ['role' => 'tool', 'tool_call_id' => $pending->tool_call_id, 'content' => $result->toJson()];

        return $this->loop($messages, $ctx, $pending->options ?? [], $pending->feature, $pending->round + 1, $this->tools, $stream);
    }

    public function executeOne(Tool $tool, array $args, ToolContext $ctx, bool $approved = false): ToolResult
    {
        return $this->executor->executeOne($tool, $args, $ctx, $approved);
    }

    private function loop(array $messages, ToolContext $ctx, array $options, string $feature, int $startRound, ToolRegistry $tools, ?StreamListener $stream = null): ToolRunResult
    {
        $agent = new ChatAgent($tools, $options, $feature, $stream);

        $state = AgentState::fresh(goal: 'Answer the customer\'s last message', messages: $messages);

        $result = $this->agents->run($agent, $state, $ctx, roundsAlreadyUsed: $startRound - 1);

        return match ($result->status) {
            AgentResult::DONE => new ToolRunResult($result->response, $result->state->messages, rounds: $result->rounds),
            AgentResult::PAUSED => new ToolRunResult($result->response, $result->state->messages, rounds: $result->rounds, pendingApprovals: $this->remember($result->pending, $result->state->messages, $options, $feature, $result->rounds)),
            default => $this->wrapUp($result, $options, $feature, $tools),
        };
    }

    private function wrapUp(AgentResult $result, array $options, string $feature, ToolRegistry $tools): ToolRunResult
    {
        $definitions = $tools->definitions();

        if ($definitions !== []) {
            $options['tools'] = $definitions;
        }

        $final = $this->ai->for($feature)->chat(ChatRequest::make($result->state->messages, ['tool_choice' => 'none'] + $options, $feature));   // a budget ran out: one last call with tools forbidden

        return new ToolRunResult($final, $result->state->messages, rounds: $result->rounds, hitCap: true);
    }

    private function remember(array $pending, array $messages, array $options, string $feature, int $round): array
    {
        foreach ($pending as $call) {
            $call->update([
                'messages' => $messages,
                'options' => Arr::except($options, ['tools']),
                'feature' => $feature,
                'round' => $round,
            ]);
        }

        return $pending;
    }
}
