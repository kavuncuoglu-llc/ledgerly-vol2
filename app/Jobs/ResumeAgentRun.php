<?php

namespace App\Jobs;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Streaming\DeltaBatcher;
use App\Ai\Streaming\ReplyChannel;
use App\Ai\Streaming\ReplyNotAuthorised;
use App\Ai\Streaming\ReplyStream;
use App\Ai\Streaming\StreamListener;
use App\Ai\Tools\ToolRunner;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

// Fig 18-7: a person decided; this job runs the gated tool (or records the refusal), lets the model carry on from there, and streams the rest into a fresh reply. The pause itself held nothing open.
final class ResumeAgentRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;                                                         // the tool may have run; a second attempt must not decide again

    public function __construct(public readonly int $pendingId, public readonly int $replyId)
    {
        $this->onQueue('ai');
    }

    public function handle(ToolRunner $runner, ConversationStore $store, CurrentTenant $tenants): void
    {
        $pending = PendingToolCall::query()->findOrFail($this->pendingId);

        $reply = Message::withoutGlobalScopes()->findOrFail($this->replyId);

        $conversation = Conversation::withoutGlobalScopes()->findOrFail($reply->conversation_id);

        $user = User::query()->findOrFail($pending->user_id);

        $this->authorise($pending, $reply, $conversation, $user);

        $tenants->run($user->tenant_id, fn () => $this->resume($pending->id, $reply->id, $runner, $store));
    }

    public function failed(Throwable $e): void
    {
        $reply = Message::withoutGlobalScopes()->find($this->replyId);

        if ($reply === null) {
            return;
        }

        if ($reply->question === null || ! in_array($reply->status, ['pending', 'streaming'], true)) {
            return;
        }

        $reply->update(['status' => 'failed', 'content' => 'Sorry, something went wrong. Our team has been notified.']);

        (new ReplyChannel($reply->id))->failed();

        report($e);
    }

    private function authorise(PendingToolCall $pending, Message $reply, Conversation $conversation, User $user): void
    {
        if ($pending->conversation_id !== $conversation->id) {
            throw new ReplyNotAuthorised('That approval belongs to a different conversation.');
        }

        if ($conversation->user_id !== $pending->user_id) {
            throw new ReplyNotAuthorised('That approval was not asked for by the conversation owner.');
        }

        if ($user->tenant_id === null) {
            throw new ReplyNotAuthorised('Staff accounts have no assistant conversations.');
        }

        if ($conversation->tenant_id !== $user->tenant_id || $reply->tenant_id !== $user->tenant_id) {
            throw new ReplyNotAuthorised('That reply is not in the approver\'s tenant.');
        }

        if (! in_array($pending->status, ['approved', 'declined'], true)) {
            throw new ReplyNotAuthorised('Nobody has decided this request yet.');
        }
    }

    private function resume(int $pendingId, int $replyId, ToolRunner $runner, ConversationStore $store): void
    {
        $pending = PendingToolCall::query()->findOrFail($pendingId);

        $reply = Message::query()->findOrFail($replyId);

        $stream = new ReplyStream($reply, new ReplyChannel($reply->id));

        $stream->start();

        $batcher = new DeltaBatcher($stream->onDelta());

        $known = count($pending->messages ?? []);

        $result = $runner->resolve(
            $pending,
            approved: $pending->status === 'approved',
            stream: new StreamListener(fn (string $delta) => $batcher->push($delta), $stream->onToolStart()),
        );

        $batcher->flushNow();

        $this->storeToolTurns($store, $reply->conversation, $pending, $result->messages, $known);

        $status = $result->pendingApprovals === []
            ? 'completed'
            : 'paused';

        $store->complete($reply, [
            'role' => 'assistant',
            'content' => $result->answer(),
            'model_call_id' => ModelCall::query()->where('conversation_id', $reply->conversation_id)->where('feature', 'support.reply')->latest('id')->value('id'),
        ], $status);

        if ($result->pendingApprovals !== []) {
            $next = $result->pendingApprovals[0];

            $stream->channel()->paused($next->summary, $next->id, $next->tool);

            return;
        }

        $stream->channel()->completed([], true);
    }

    private function storeToolTurns(ConversationStore $store, Conversation $conversation, PendingToolCall $pending, array $messages, int $known): void
    {
        $decided = $messages[$known] ?? null;

        if ($decided !== null) {
            Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', 'tool')
                ->where('tool_call_id', $pending->tool_call_id)
                ->update(['content' => $decided['content']]);                      // the "awaiting approval" note becomes what really happened
        }

        foreach (array_slice($messages, $known + 1) as $message) {
            $store->append($conversation, $message);
        }
    }
}
