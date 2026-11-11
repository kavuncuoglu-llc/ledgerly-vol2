<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Streaming\ReplyNotAuthorised;
use App\Ai\Streaming\ReplyStarter;
use App\Ai\Tools\ToolRunner;
use App\Events\ReplyCompleted;
use App\Events\ReplyPaused;
use App\Jobs\ResumeAgentRun;
use App\Models\Message;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\RefundTool;

beforeEach(function () {
    Sleep::fake();

    RefundTool::$runs = 0;

    bindTools(new RefundTool);

    [$this->user] = customerWithInvoice();

    Http::fake(['*/chat/completions' => streamResponses(
        streamToolCallBody([['call_9', 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6]]]),
        streamBody(['I refunded $21.60.']),
    )]);

    Event::fake([ReplyPaused::class, ReplyCompleted::class]);

    $this->actingAs($this->user);

    $this->paused = app(ReplyStarter::class)->start($this->user, 'Please refund the late fee on 4471');

    $this->pending = PendingToolCall::sole();
});

it('pauses the reply for approval, ends the job and tells the page what is being waited for', function () {
    expect($this->paused->fresh()->status)->toBe('paused')
        ->and($this->paused->fresh()->content)->toBe('I need your approval before I can do this: Refund $21.60 late fee on invoice #4471')
        ->and(RefundTool::$runs)->toBe(0);

    Event::assertDispatched(ReplyPaused::class, fn (ReplyPaused $event) => $event->replyId === $this->paused->id && $event->pendingId === $this->pending->id && $event->summary === 'Refund $21.60 late fee on invoice #4471');

    Event::assertNotDispatched(ReplyCompleted::class);

    $this->getJson(route('replies.show', $this->paused))
        ->assertOk()
        ->assertJsonPath('status', 'paused')
        ->assertJsonPath('pending.id', $this->pending->id)
        ->assertJsonPath('pending.summary', 'Refund $21.60 late fee on invoice #4471');
});

it('queues the resumed run on the ai queue and returns at once', function () {
    Queue::fake();

    $this->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])
        ->assertStatus(202)
        ->assertJsonPath('status', 'resuming')
        ->assertJsonPath('channel', fn (string $channel) => str_starts_with($channel, 'reply.'));

    Queue::assertPushedOn('ai', ResumeAgentRun::class);

    expect(RefundTool::$runs)->toBe(0)
        ->and($this->pending->fresh()->status)->toBe('approved');
});

it('runs the tool in the job, streams the rest into a new reply and writes the real result over the waiting note', function () {
    $reply = Message::query()->findOrFail(
        $this->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertStatus(202)->json('reply_id'),
    );

    expect($reply->fresh()->status)->toBe('completed')
        ->and($reply->fresh()->content)->toBe('I refunded $21.60.')
        ->and(RefundTool::$runs)->toBe(1)
        ->and(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['pending_approval', 'approved']);

    $tool = Message::query()->where('conversation_id', $reply->conversation_id)->where('role', 'tool')->sole();

    expect($tool->content)->not->toContain('awaiting approval');

    Event::assertDispatched(ReplyCompleted::class, fn (ReplyCompleted $event) => $event->replyId === $reply->id);
});

it('records a refusal and lets the model say so', function () {
    Http::swap(new Factory);

    Http::fake(['*/chat/completions' => streamResponses(streamBody(['Understood, I have not refunded anything.']))]);

    $replyId = $this->postJson(route('support.decline', $this->pending))->assertStatus(202)->json('reply_id');

    expect(Message::query()->findOrFail($replyId)->content)->toBe('Understood, I have not refunded anything.')
        ->and(RefundTool::$runs)->toBe(0)
        ->and($this->pending->fresh()->status)->toBe('declined');
});

it('answers a second press of the button with a 409 and runs nothing twice', function () {
    $this->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertStatus(202);

    $this->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertStatus(410);

    $this->postJson(route('support.decline', $this->pending))->assertStatus(410);

    expect(RefundTool::$runs)->toBe(1);
});

it('refuses everyone but the person the assistant asked', function () {
    Queue::fake();

    $colleague = User::factory()->inTenant($this->user->tenant)->create();
    [$stranger] = customerWithInvoice('INV-9001');

    foreach ([$colleague, $stranger] as $other) {
        $this->actingAs($other)->postJson(route('support.approve', $this->pending), ['token' => approvalToken($this->pending)])->assertForbidden();
    }

    Queue::assertNothingPushed();

    expect($this->pending->fresh()->status)->toBe('pending')
        ->and(RefundTool::$runs)->toBe(0);
});

it('refuses a resume job that was built for somebody else\'s conversation', function () {
    Queue::fake();

    [$stranger] = customerWithInvoice('INV-9002');

    $this->actingAs($stranger);

    $theirs = app(ReplyStarter::class)->start($stranger, 'Hello');

    PendingToolCall::query()->whereKey($this->pending->id)->update(['status' => 'approved']);

    $job = new ResumeAgentRun($this->pending->id, $theirs->id);

    Http::fake();

    expect(fn () => $job->handle(app(ToolRunner::class), app(ConversationStore::class), app(CurrentTenant::class)))
        ->toThrow(ReplyNotAuthorised::class);

    Http::assertNothingSent();

    expect(RefundTool::$runs)->toBe(0);
});

it('refuses a resume job nobody has decided yet', function () {
    Queue::fake();

    $reply = app(ConversationStore::class)->placeholder($this->paused->conversation, 'Continue');

    $job = new ResumeAgentRun($this->pending->id, $reply->id);

    expect(fn () => $job->handle(app(ToolRunner::class), app(ConversationStore::class), app(CurrentTenant::class)))
        ->toThrow(ReplyNotAuthorised::class, 'Nobody has decided');

    expect(RefundTool::$runs)->toBe(0);
});
