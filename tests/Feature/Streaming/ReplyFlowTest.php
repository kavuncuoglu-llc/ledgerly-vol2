<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Streaming\ReplyStarter;
use App\Ai\Support\SupportAssistant;
use App\Events\ReplyCompleted;
use App\Events\ReplyDelta;
use App\Events\ReplyFailed;
use App\Events\ToolCallStarted;
use App\Jobs\AnswerQuestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    [$this->user, $this->invoice] = customerWithInvoice();
});

function replyOf(User $user, string $question = 'What is a late fee?'): Message
{
    test()->actingAs($user);

    return app(ReplyStarter::class)->start($user, $question);
}

describe('GET /replies/{reply}', function () {
    it('gives the owner the stored message: the source of truth a dropped socket recovers from', function () {
        Http::fake(['*/chat/completions' => streamReply(['A late fee ', 'is a charge.'])]);

        $reply = replyOf($this->user);

        $this->actingAs($this->user)
            ->getJson(route('replies.show', $reply))
            ->assertOk()
            ->assertJson(['id' => $reply->id, 'status' => 'completed', 'content' => 'A late fee is a charge.', 'sources' => [], 'pending' => null]);
    });

    it('shows a reply that is still waiting as pending with no content', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        $this->actingAs($this->user)->getJson(route('replies.show', $reply))->assertOk()->assertJson(['status' => 'pending', 'content' => '']);
    });

    it('is a 404 for a colleague, another tenant and staff, and a 401 for a guest', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        $colleague = User::factory()->inTenant($this->user->tenant)->create();
        [$stranger] = customerWithInvoice('INV-9001');

        foreach ([$colleague, $stranger, User::factory()->support()->create()] as $other) {
            $this->actingAs($other)->getJson(route('replies.show', $reply))->assertNotFound();
        }

        auth()->logout();

        $this->getJson(route('replies.show', $reply))->assertUnauthorized();
    });

    it('does not serve a user turn or a tool message as if it were a reply', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        $question = Message::query()->where('conversation_id', $reply->conversation_id)->where('role', 'user')->sole();

        $this->actingAs($this->user)->getJson(route('replies.show', $question))->assertNotFound();
    });

    it('records thumbs up and down on your own reply only', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        $this->actingAs($this->user)->postJson(route('replies.feedback', $reply), ['value' => 'up'])->assertOk()->assertJson(['feedback' => 'up']);

        $this->actingAs($this->user)->postJson(route('replies.feedback', $reply), ['value' => 'sideways'])->assertUnprocessable();

        $this->actingAs(User::factory()->inTenant($this->user->tenant)->create())->postJson(route('replies.feedback', $reply), ['value' => 'down'])->assertNotFound();

        expect($reply->fresh()->feedback)->toBe('up');
    });
});

describe('AnswerQuestion', function () {
    it('is unique per reply, on the ai queue, with the settings from Figure 18-3', function () {
        $job = new AnswerQuestion(42);

        expect($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe('42')
            ->and($job->queue)->toBe('ai')
            ->and([$job->timeout, $job->tries, $job->backoff])->toBe([180, 3, [5, 15, 60]]);

        expect(config('ai.agents.support.max_seconds'))->toBeLessThan($job->timeout);   // the agent budget ends long runs, not the job timeout
    });

    it('does not start twice for a double-submitted reply', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        AnswerQuestion::dispatch($reply->id);                                          // the form was submitted twice

        Queue::assertPushed(AnswerQuestion::class, 1);                                 // ShouldBeUnique on the reply id: the second push is dropped
    });

    it('updates the placeholder instead of creating a second reply, and puts it after the tool turns', function () {
        Http::fake(['*/chat/completions' => streamResponses(
            streamToolCallBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            streamBody(['Invoice #4471 is 18 days overdue.']),
        )]);

        $reply = replyOf($this->user, 'Why is invoice 4471 late?');

        $messages = Message::query()->where('conversation_id', $reply->conversation_id)->orderBy('seq')->get();

        expect($messages->pluck('role')->all())->toBe(['user', 'assistant', 'tool', 'assistant'])
            ->and($messages->last()->id)->toBe($reply->id)
            ->and($messages->last()->content)->toBe('Invoice #4471 is 18 days overdue.')
            ->and($messages->last()->model_call_id)->not->toBeNull()
            ->and($messages->pluck('seq')->all())->toBe($messages->pluck('seq')->sort()->values()->all());   // the placeholder moved to the end; its old seq is simply unused
    });

    it('tells the page which tool is running while the arguments are still arriving', function () {
        Event::fake([ReplyDelta::class, ReplyCompleted::class, ToolCallStarted::class]);

        Http::fake(['*/chat/completions' => streamResponses(
            streamToolCallBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            streamBody(['Done.']),
        )]);

        $reply = replyOf($this->user, 'Why is invoice 4471 late?');

        Event::assertDispatched(ToolCallStarted::class, fn (ToolCallStarted $event) => $event->replyId === $reply->id && $event->tool === 'lookup_invoice');
    });

    it('does nothing on a retry after the reply completed', function () {
        Http::fake(['*/chat/completions' => streamReply(['Once.'])]);

        $reply = replyOf($this->user);

        (new AnswerQuestion($reply->id))->handle(app(SupportAssistant::class), app(CurrentTenant::class));

        expect(Http::recorded())->toHaveCount(1)
            ->and(ModelCall::count())->toBe(1)
            ->and(Message::query()->where('role', 'assistant')->count())->toBe(1);
    });

    it('keeps going when the broadcast server is down: the stored message is the truth', function () {
        Log::spy();

        Event::listen(ReplyDelta::class, fn () => throw new RuntimeException('Connection refused'));
        Event::listen(ReplyCompleted::class, fn () => throw new RuntimeException('Connection refused'));

        Http::fake(['*/chat/completions' => streamReply(['One ', 'two ', 'three.'])]);

        $reply = replyOf($this->user);

        expect($reply->fresh()->status)->toBe('completed')
            ->and($reply->fresh()->content)->toBe('One two three.');

        Log::shouldHaveReceived('warning')->once();                                    // once per reply, not once per delta
    });

    it('saves the text so far while streaming, so a reconnecting page or the poll sees progress', function () {
        Http::fake(['*/chat/completions' => streamReply(['First ', 'second ', 'third.'])]);

        config(['ai.replies.stale_minutes' => 15]);

        $reply = replyOf($this->user);

        expect($reply->fresh()->content)->toBe('First second third.');
    });

    it('does not replay a pending, failed or unfinished reply to the model on the next question', function () {
        Http::fake(['*/chat/completions' => streamReply(['Fine.'])]);

        $conversation = app(ConversationStore::class)->start($this->user, SupportPrompt::VERSION);

        app(ConversationStore::class)->append($conversation, ['role' => 'user', 'content' => 'First question']);

        $broken = app(ConversationStore::class)->placeholder($conversation, 'First question');

        app(ConversationStore::class)->complete($broken, ['role' => 'assistant', 'content' => 'Sorry, something went wrong. Our team has been notified.'], 'failed');

        $this->actingAs($this->user);

        app(ReplyStarter::class)->start($this->user, 'Second question', $conversation);

        $sent = collect(sentChatBodies()[0]['messages'])->pluck('content')->implode("\n");

        expect($sent)->not->toContain('Sorry, something went wrong')
            ->and($sent)->toContain('Second question');
    });

    it('carries the answer into the next question of the same conversation', function () {
        Http::fake(['*/chat/completions' => streamResponses(streamBody(['Fees start after 30 days.']), streamBody(['Yes, 30 days.']))]);

        $first = replyOf($this->user, 'When do late fees start?');

        $this->actingAs($this->user);

        app(ReplyStarter::class)->start($this->user, 'Is that 30 days?', $first->conversation);

        $second = sentChatBodies()[1]['messages'];

        expect(collect($second)->pluck('content')->implode("\n"))->toContain('Fees start after 30 days.');
    });
});

describe('AnswerQuestion authorisation and failure', function () {
    it('refuses a placeholder whose tenant is not its owner\'s tenant, without calling the model', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        Message::withoutGlobalScopes()->whereKey($reply->id)->update(['tenant_id' => Tenant::factory()->create()->id]);

        Http::fake();

        $job = new AnswerQuestion($reply->id);

        $job->handle(app(SupportAssistant::class), app(CurrentTenant::class));

        Http::assertNothingSent();

        expect(Message::withoutGlobalScopes()->find($reply->id)->status)->toBe('pending');
    });

    it('refuses a message id that is not a reply placeholder and leaves that message alone', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        Http::fake();

        $question = Message::query()->where('conversation_id', $reply->conversation_id)->where('role', 'user')->sole();

        $job = new AnswerQuestion($question->id);

        $job->handle(app(SupportAssistant::class), app(CurrentTenant::class));
        $job->failed(new RuntimeException('x'));

        Http::assertNothingSent();

        expect($question->fresh()->content)->toBe('What is a late fee?')
            ->and($question->fresh()->status)->toBe('completed');
    });

    it('refuses a placeholder of a user who belongs to no tenant', function () {
        Queue::fake();

        $reply = replyOf($this->user);

        User::query()->whereKey($this->user->id)->update(['tenant_id' => null]);

        Http::fake();

        (new AnswerQuestion($reply->id))->handle(app(SupportAssistant::class), app(CurrentTenant::class));

        Http::assertNothingSent();
    });

    it('marks the reply failed, broadcasts it and records the failure when the last retry fails', function () {
        Event::fake([ReplyFailed::class]);

        Queue::fake();

        $reply = replyOf($this->user);

        Message::query()->whereKey($reply->id)->update(['status' => 'streaming']);

        (new AnswerQuestion($reply->id))->failed(new RuntimeException('The model server fell over'));

        expect($reply->fresh()->status)->toBe('failed')
            ->and($reply->fresh()->content)->toBe('Sorry, something went wrong. Our team has been notified.');

        Event::assertDispatched(ReplyFailed::class, fn (ReplyFailed $event) => $event->replyId === $reply->id);

        expect(ModelCall::sole())->feature->toBe('support.reply')
            ->and(ModelCall::sole()->finish_reason)->toBe('error');
    });

    it('lets a failed reply be asked again as a new reply', function () {
        Http::fake(['*/chat/completions' => streamReply(['Second time lucky.'])]);

        $conversation = app(ConversationStore::class)->start($this->user, SupportPrompt::VERSION);

        $this->actingAs($this->user);

        $retry = app(ReplyStarter::class)->start($this->user, 'What is a late fee?', $conversation);

        expect($retry->fresh()->status)->toBe('completed');
    });

    it('staff cannot start a reply: they have no conversations', function () {
        $this->actingAs(User::factory()->support()->create())
            ->postJson('support/ask', ['question' => 'Hello'])
            ->assertForbidden();

        expect(Conversation::count())->toBe(0);
    });
});

describe('the sweep', function () {
    it('fails a reply whose worker died, so no page spins forever, and leaves a live one alone', function () {
        Queue::fake();

        Event::fake([ReplyFailed::class]);

        $stale = replyOf($this->user);
        $live = replyOf($this->user, 'Another');

        Message::query()->whereKey($stale->id)->update(['status' => 'streaming', 'updated_at' => now()->subMinutes(30)]);
        Message::query()->whereKey($live->id)->update(['status' => 'streaming']);

        $this->artisan('replies:sweep')->expectsOutputToContain('failed 1 stale replies')->assertSuccessful();

        expect($stale->fresh()->status)->toBe('failed')
            ->and($live->fresh()->status)->toBe('streaming');

        Event::assertDispatched(ReplyFailed::class, 1);
    });

    it('expires an approval nobody answered for seven days and refuses it afterwards', function () {
        $pending = PendingToolCall::query()->create([
            'user_id' => $this->user->id, 'tool' => 'issue_refund', 'tool_call_id' => 'c1', 'arguments' => [], 'summary' => 'Refund', 'status' => 'pending',
        ]);

        PendingToolCall::query()->whereKey($pending->id)->update(['created_at' => now()->subDays(8)]);

        $this->artisan('replies:sweep')->expectsOutputToContain('Expired 1 unanswered approvals')->assertSuccessful();

        expect($pending->fresh()->status)->toBe('expired');

        $this->actingAs($this->user)->postJson(route('support.approve', $pending))->assertStatus(409);
    });
});
