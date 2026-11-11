<?php

use App\Ai\Prompts\SupportPrompt;
use App\Events\ReplyCompleted;
use App\Events\ReplyDelta;
use App\Events\ReplyFailed;
use App\Events\ReplyPaused;
use App\Events\ToolCallStarted;
use App\Jobs\AnswerQuestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

function fakeSupportReply(string $content = 'A late fee is an extra charge added to an invoice after the due date.', string $finish = 'stop'): void
{
    $deltas = preg_split('/(?<= )/', $content);

    Http::fake(['*/chat/completions' => streamReply($deltas, finish: $finish, usage: ['prompt_tokens' => 112, 'completion_tokens' => 74])]);
}

beforeEach(function () {
    Sleep::fake();

    Event::fake([ReplyDelta::class, ReplyCompleted::class, ReplyFailed::class, ToolCallStarted::class, ReplyPaused::class]);
});

it('queues the question and returns a reply id and channel at once', function () {
    fakeSupportReply();

    $user = User::factory()->owner()->create();

    $response = $this->actingAs($user)
        ->postJson('support/ask', ['question' => 'What is a late fee?'])
        ->assertStatus(202);

    $reply = Message::query()->where('role', 'assistant')->sole();

    $response->assertExactJson([
        'reply_id' => $reply->id,
        'channel' => "reply.{$reply->id}",
        'conversation_id' => Conversation::sole()->id,
    ]);

    expect($reply->question)->toBe('What is a late fee?')
        ->and($reply->status)->toBe('completed')                                    // the sync queue ran the job inline
        ->and($reply->content)->toBe('A late fee is an extra charge added to an invoice after the due date.');
});

it('puts the job on the ai queue and runs nothing in the request', function () {
    Queue::fake();

    Http::fake();

    $this->actingAs(User::factory()->owner()->create())
        ->postJson('support/ask', ['question' => 'What is a late fee?'])
        ->assertStatus(202);

    Queue::assertPushedOn('ai', AnswerQuestion::class);

    Http::assertNothingSent();

    expect(Message::query()->where('role', 'assistant')->sole()->status)->toBe('pending');
});

it('streams with the v2 prompt, the question, the assistant profile and the answer budget', function () {
    fakeSupportReply();

    $this->actingAs(User::factory()->create())->postJson('support/ask', ['question' => 'What is a late fee?']);

    Http::assertSent(fn (Request $request) => $request['messages'] === SupportPrompt::messages('What is a late fee?')
        && $request['temperature'] === 0.7
        && $request['top_p'] === 0.9
        && $request['max_tokens'] === 400
        && $request['stream'] === true);
});

it('logs the call as support.reply', function () {
    fakeSupportReply();

    $this->actingAs(User::factory()->create())->postJson('support/ask', ['question' => 'What is a late fee?']);

    $call = ModelCall::sole();

    expect($call->feature)->toBe('support.reply')
        ->and($call->prompt_tokens)->toBe(112)
        ->and($call->completion_tokens)->toBe(74)
        ->and($call->finish_reason)->toBe('stop');
});

it('broadcasts the reply as batched deltas and a completion on the private reply channel', function () {
    fakeSupportReply();

    $this->actingAs(User::factory()->create())->postJson('support/ask', ['question' => 'What is a late fee?']);

    $reply = Message::query()->where('role', 'assistant')->sole();

    Event::assertDispatched(ReplyDelta::class, fn (ReplyDelta $event) => $event->replyId === $reply->id && $event->offset === 0 && str_starts_with($event->text, 'A late fee is'));

    Event::assertDispatched(ReplyCompleted::class, fn (ReplyCompleted $event) => $event->replyId === $reply->id && $event->citationsOk === true);

    Event::assertNotDispatched(ReplyFailed::class);

    expect(collect(Event::dispatched(ReplyDelta::class))->map(fn ($call) => $call[0]->text)->implode(''))->toBe($reply->content);
});

it('records the finish reason when the reply was cut off by the token limit', function () {
    fakeSupportReply('A late fee is', finish: 'length');

    $this->actingAs(User::factory()->create())
        ->postJson('support/ask', ['question' => 'What is a late fee?'])
        ->assertStatus(202);

    $reply = Message::query()->where('role', 'assistant')->sole();

    expect(ModelCall::sole()->finish_reason)->toBe('length')
        ->and($reply->status)->toBe('truncated')                                    // Chapter 29: flagged, and never shown as if it were complete
        ->and($reply->content)->toBe(SupportPrompt::truncatedMessage());
});

it('turns away a guest', function () {
    Http::fake();

    $this->postJson('support/ask', ['question' => 'What is a late fee?'])->assertUnauthorized();

    Http::assertNothingSent();
});

it('sends a guest to the sign-in page', function () {
    $this->get('support')->assertRedirect(route('login'));
});

it('validates the question', function (mixed $question) {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('support/ask', ['question' => $question])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('question');

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'empty' => [''],
    'too long' => [str_repeat('a', 2001)],
    'not a string' => [['x']],
]);

it('says so when the model server is down, and the person gets the notice, not a failed job (Chapter 25)', function () {
    Http::fake(['*/chat/completions' => Http::failedConnection()]);

    $this->actingAs(User::factory()->create())
        ->postJson('support/ask', ['question' => 'What is a late fee?'])
        ->assertStatus(202);                                                                // the job finished: nothing for Horizon to retry or list as failed

    $reply = Message::query()->where('role', 'assistant')->sole();

    expect($reply->status)->toBe('failed')
        ->and($reply->content)->toBe(SupportPrompt::unavailableMessage());
});

it('marks the reply failed instead of spinning forever when the job itself fails for another reason', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'invalid request']], 400)]);

    $this->actingAs(User::factory()->create())
        ->postJson('support/ask', ['question' => 'What is a late fee?'])
        ->assertStatus(500);

    $reply = Message::query()->where('role', 'assistant')->sole();

    expect($reply->status)->toBe('failed')
        ->and($reply->content)->toBe('Sorry, something went wrong. Our team has been notified.');

    Event::assertDispatched(ReplyFailed::class, fn (ReplyFailed $event) => $event->replyId === $reply->id);
});

it('throttles a user who asks too often', function () {
    fakeSupportReply();

    $user = User::factory()->create();

    foreach (range(1, 20) as $attempt) {
        $this->actingAs($user)->postJson('support/ask', ['question' => 'Again?'])->assertStatus(202);
    }

    $this->actingAs($user)->postJson('support/ask', ['question' => 'Again?'])->assertStatus(429);
});

it('shows the ask page with the three demo questions', function () {
    $this->actingAs(User::factory()->create())
        ->get('support')
        ->assertOk()
        ->assertSeeText('What is a late fee?')
        ->assertSeeText('What late fee does Ledgerly charge?')
        ->assertSeeText('Why is my March invoice marked late?')
        ->assertSee(route('support.ask'));
});

describe('sign in', function () {
    it('shows the form', function () {
        $this->get('login')->assertOk()->assertSee('name="email"', false);
    });

    it('signs a seeded user in with the password "password" and lands on the ask page', function () {
        $user = User::factory()->owner()->create(['email' => 'maya.okafor@acme-studios.example.com']);

        $this->post('login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('support.show'));

        $this->assertAuthenticatedAs($user);
    });

    it('sends you back to the page you asked for', function () {
        $user = User::factory()->create();

        $this->get('support');

        $this->post('login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('support.show'));
    });

    it('rejects a wrong password', function () {
        $user = User::factory()->create();

        $this->post('login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    it('validates the fields', function () {
        $this->post('login', [])->assertSessionHasErrors(['email', 'password']);
    });

    it('signs out', function () {
        $this->actingAs(User::factory()->create())
            ->post('logout')
            ->assertRedirect(route('dashboard'));

        $this->assertGuest();
    });

    it('keeps a signed-in user off the form', function () {
        $this->actingAs(User::factory()->create())->get('login')->assertRedirect();
    });
});
