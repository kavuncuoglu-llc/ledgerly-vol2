<?php

namespace App\Ai\Streaming;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tools\PendingAction;
use App\Jobs\AnswerQuestion;
use App\Jobs\ResumeAgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PendingToolCall;
use App\Models\User;

// Fig 18-9: validate, store the question and an empty reply, queue the work, return. Nothing slow happens in the request.
final class ReplyStarter
{
    public function __construct(private ConversationStore $store) {}

    public function start(User $user, string $question, ?Conversation $conversation = null): Message
    {
        $conversation ??= $this->store->start($user, SupportPrompt::VERSION);

        $this->store->append($conversation, ['role' => 'user', 'content' => $question]);

        $reply = $this->store->placeholder($conversation, $question);               // status: pending

        AnswerQuestion::dispatch($reply->id);

        return $reply;
    }

    public function resume(User $user, PendingToolCall $pending, bool $approved): Message
    {
        $conversation = Conversation::query()
            ->where('user_id', $user->id)                                           // the approver must own the conversation the request came from
            ->findOrFail($pending->conversation_id);

        abort_unless(PendingAction::claim($pending, $user, $approved), 409, 'This request has already been answered.');     // one decision, once, even if the button is pressed twice

        $reply = $this->store->placeholder($conversation, 'Continue after your decision: '.$pending->summary);

        ResumeAgentRun::dispatch($pending->id, $reply->id);

        return $reply;
    }
}
