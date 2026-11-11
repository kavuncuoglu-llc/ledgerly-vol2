<?php

namespace App\Jobs;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\NoTenant;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\RateLimited as RateLimitedByProvider;
use App\Ai\Experiments\Experiment;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Streaming\ReplyChannel;
use App\Ai\Streaming\ReplyNotAuthorised;
use App\Ai\Streaming\ReplyStream;
use App\Ai\Support\SupportAssistant;
use App\Facades\Ai;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

final class AnswerQuestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;           // > agent budget (120 s) + retrieval + tools: the budget ends long runs, so the trace survives

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public int $uniqueFor = 600;         // a double-submitted form must not run twice; a crashed worker must not block the reply forever

    public function __construct(public readonly int $replyId)
    {
        $this->onQueue('ai');            // Queueable declares $queue untyped, so the book's `public string $queue` cannot be redeclared here
    }

    public function uniqueId(): string
    {
        return (string) $this->replyId;
    }

    // Chapter 35, Topic 6: spend is rate-limited per provider and a 429 backs off instead of counting as a failure. The limiter is named for the provider the feature uses ('ai:frontier').
    public function middleware(): array
    {
        return [
            new RateLimited('ai:'.Ai::providerFor('support.reply')),
            (new ThrottlesExceptions(3, 5 * 60))->when(fn (Throwable $e) => $e instanceof RateLimitedByProvider)->backoff(1),
        ];
    }

    public function handle(SupportAssistant $assistant, CurrentTenant $tenants): void
    {
        $reply = Message::withoutGlobalScopes()->findOrFail($this->replyId);       // a worker has no signed-in user: authorise from the rows themselves

        if (in_array($reply->status, ['completed', 'paused'], true)) {
            return;                                                                // retry after success: no-op
        }

        $conversation = Conversation::withoutGlobalScopes()->findOrFail($reply->conversation_id);

        $user = User::query()->findOrFail($conversation->user_id);

        try {
            $this->authorise($reply, $conversation, $user);
        } catch (Throwable $e) {
            $this->fail($e);                                                       // retrying an authorisation failure only repeats it

            return;
        }

        $tenants->run($user->tenant_id, fn () => $this->answer($assistant, $reply->id));
    }

    public function failed(Throwable $e): void
    {
        $reply = Message::withoutGlobalScopes()->find($this->replyId);

        if ($reply === null) {
            return;
        }

        if ($reply->question === null || ! in_array($reply->status, ['pending', 'streaming'], true)) {
            return;                                                                // only a reply still waiting for its answer can fail; never overwrite a real message
        }

        $reply->update(['status' => 'failed', 'content' => 'Sorry, something went wrong. Our team has been notified.']);

        (new ReplyChannel($reply->id))->failed();

        ModelCalled::dispatch('support.reply', Ai::providerFor('support.reply'), Ai::model('local'), ['prompt_tokens' => 0, 'completion_tokens' => 0], 'error', 0, $reply->conversation_id);   // the Chapter 14 stats see the failure

        report($e);
    }

    private function authorise(Message $reply, Conversation $conversation, User $user): void
    {
        if ($reply->role !== 'assistant' || $reply->question === null) {
            throw new ReplyNotAuthorised("Message {$reply->id} is not a reply placeholder.");
        }

        if ($user->tenant_id === null) {
            throw NoTenant::forUser($user->id);
        }

        if ($reply->tenant_id !== $conversation->tenant_id || $conversation->tenant_id !== $user->tenant_id) {
            throw new AuthorizationException("Reply {$reply->id} does not belong to the tenant of the user who asked.");
        }
    }

    private function answer(SupportAssistant $assistant, int $replyId): void
    {
        $reply = Message::query()->findOrFail($replyId);                           // the tenant scope applies from here on

        $stream = new ReplyStream($reply, new ReplyChannel($reply->id));

        if ($this->customerHasLeft($reply)) {
            $this->replyFromTemplate($reply, $stream);                             // no model call for a customer who is no longer there

            return;
        }

        $stream->start();

        $result = $assistant->answerStreaming(
            $reply->conversation,
            (string) $reply->question,
            onDelta: $stream->onDelta(),
            onToolStart: $stream->onToolStart(),
            reply: $reply,
        );

        $reply->refresh();

        if ($result->pendingApprovals !== []) {
            $pending = $result->pendingApprovals[0];

            $stream->channel()->paused($pending->summary, $pending->id, $pending->tool);       // the job ends here; nothing is held open while a person decides

            return;
        }

        if (in_array($reply->status, ['truncated', 'blocked'], true)) {
            $stream->channel()->failed();                                          // the page re-reads the stored reply, which says it was cut off, or that a check stopped it

            return;
        }

        $stream->channel()->completed($this->sources($reply), $result->citations === null || ! $result->citations->hasErrors());

        $this->shadow($reply);
    }

    // Chapter 35, Topic 6: a backlog that sat through an outage is mostly questions from people who left hours ago.
    private function customerHasLeft(Message $reply): bool
    {
        $minutes = (int) config('ai.replies.freshness_minutes', 30);

        return $minutes > 0
            && $reply->created_at !== null
            && $reply->created_at->lt(now()->subMinutes($minutes));
    }

    private function replyFromTemplate(Message $reply, ReplyStream $stream): void
    {
        app(ConversationStore::class)->complete($reply, ['role' => 'assistant', 'content' => SupportPrompt::delayedMessage()]);

        $stream->channel()->completed([], true);
    }

    // Chapter 30, Topic 4: a share of real questions is re-answered by the candidate, after the customer has their answer. Nothing here can slow or change the reply.
    private function shadow(Message $reply): void
    {
        $settings = Experiment::running('support.reply', 'shadow');

        if ($settings === null || $reply->retrieved === null) {
            return;                                                                // a cache hit has no sources to share, so there is nothing fair to compare
        }

        if (! Experiment::shadowSampled($reply->id, (int) $settings['treatment_percent'])) {
            return;
        }

        ShadowAnswer::dispatch($reply->id);
    }

    private function sources(Message $reply): array
    {
        return MessageSource::query()
            ->where('message_id', $reply->id)
            ->get(['label', 'url'])
            ->map(fn (MessageSource $source) => ['label' => $source->label, 'url' => $source->url])
            ->all();
    }
}
