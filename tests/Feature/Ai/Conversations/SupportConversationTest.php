<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Security\Untrusted;
use App\Ai\Tokens\RatioCounter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RefundTool;

beforeEach(function () {
    Sleep::fake();

    [$this->user] = customerWithInvoice();
});

function ask(User $user, string $question, ?int $conversationId = null)
{
    return askAssistant($user, array_filter(['question' => $question, 'conversation_id' => $conversationId], fn ($v) => $v !== null));
}

describe('the endpoint', function () {
    it('starts a conversation and returns its id', function () {
        fakeChatSequence(chatBody('Hello.'));

        $id = ask($this->user, 'Hi')->assertOk()->json('conversation_id');

        $conversation = Conversation::findOrFail($id);

        expect($conversation->user_id)->toBe($this->user->id)
            ->and($conversation->tenant_id)->toBe($this->user->tenant_id)
            ->and($conversation->prompt_version)->toBe(SupportPrompt::VERSION);
    });

    it('stores the question as asked, the answer, and links the answer to its model call', function () {
        fakeChatSequence(chatBody('Hello there.'));

        ask($this->user, 'Hi');

        $messages = Message::orderBy('seq')->get();

        expect($messages->pluck('role')->all())->toBe(['user', 'assistant'])
            ->and($messages[0]->content)->toBe('Hi')                                      // not the Context: wrapper
            ->and($messages[1]->content)->toBe('Hello there.')
            ->and($messages[1]->model_call_id)->toBe(ModelCall::where('feature', 'support.reply')->sole()->id)
            ->and($messages[1]->tokens)->toBe((new RatioCounter)->count('Hello there.'));
    });

    it('logs the call against the conversation and the prompt version', function () {
        fakeChatSequence(chatBody('Hello.'));

        $id = ask($this->user, 'Hi')->json('conversation_id');

        $call = ModelCall::sole();

        expect($call->conversation_id)->toBe($id)
            ->and($call->prompt_version)->toBe(SupportPrompt::VERSION);

        Http::assertSent(fn (Request $request) => ! array_key_exists('conversation_id', $request->data()));   // an internal option: never sent to the server
    });

    it('sends the earlier turns before the new question on the next request', function () {
        fakeChatSequence(chatBody('March is 18 days overdue.'), chatBody('April is not due yet.'));

        $id = ask($this->user, 'Why is my March invoice late?')->json('conversation_id');

        ask($this->user, 'What about the April one?', $id)->assertOk()->assertJson(['conversation_id' => $id]);

        $messages = sentChatBodies()[1]['messages'];

        expect(array_column($messages, 'role'))->toBe(['system', 'user', 'assistant', 'user', 'user'])
            ->and($messages[1]['content'])->toBe(Untrusted::wrap('Why is my March invoice late?', 'customer_message'))
            ->and($messages[2]['content'])->toBe('March is 18 days overdue.')
            ->and($messages[3]['content'])->toBe("Context:\n(no context provided)")
            ->and($messages[4]['content'])->toBe("Question:\n".Untrusted::wrap('What about the April one?', 'customer_message'));
    });

    it('does not pin retrieved context into the history', function () {
        fakeChatSequence(chatBody('One.'), chatBody('Two.'));

        $id = ask($this->user, 'First')->json('conversation_id');
        ask($this->user, 'Second', $id);

        expect(Message::pluck('content')->join('|'))->not->toContain('Context:');
    });

    it('starts a fresh conversation without an id', function () {
        fakeChatSequence(chatBody('One.'), chatBody('Two.'));

        $first = ask($this->user, 'First')->json('conversation_id');
        $second = ask($this->user, 'Second')->json('conversation_id');

        expect($second)->not->toBe($first)
            ->and(sentChatBodies()[1]['messages'])->toHaveCount(3);
    });

    it('does not let you continue someone else\'s conversation, even in your own tenant', function () {
        fakeChatSequence(chatBody('Hello.'));

        $colleague = User::factory()->inTenant($this->user->tenant)->create();

        $theirs = conversationFor($colleague);

        ask($this->user, 'Hi', $theirs->id)->assertNotFound();
    });

    it('does not let you continue another tenant\'s conversation', function () {
        $stranger = User::factory()->create();

        $theirs = conversationFor($stranger);

        ask($this->user, 'Hi', $theirs->id)->assertNotFound();
    });

    it('refuses to continue a closed conversation', function () {
        $closed = conversationFor($this->user, status: 'closed');

        $this->actingAs($this->user)->postJson('support/ask', ['question' => 'Hi', 'conversation_id' => $closed->id])->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    });

    it('answers a member of staff with a 403 instead of a conversation', function () {
        Http::fake();

        $this->actingAs(User::factory()->support()->create())->postJson('support/ask', ['question' => 'Hi'])->assertForbidden();

        Http::assertNothingSent();
    });

    it('validates the conversation id', function () {
        ask($this->user, 'Hi', 0)->assertNotFound();

        test()->actingAs($this->user)->postJson('support/ask', ['question' => 'Hi', 'conversation_id' => 'abc'])->assertUnprocessable();
    });
});

describe('tool exchanges', function () {
    it('stores the tool turns verbatim and replays them with their ids', function () {
        fakeChatSequence(
            toolCallsBody([['call_8a1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('INV-4471 is 18 days overdue.'),
            chatBody('Still overdue.'),
        );

        $id = ask($this->user, 'Why is INV-4471 late?')->json('conversation_id');

        $stored = Message::orderBy('seq')->get();

        expect($stored->pluck('role')->all())->toBe(['user', 'assistant', 'tool', 'assistant'])
            ->and($stored[1]->content)->toBeNull()
            ->and($stored[1]->tool_calls[0]['id'])->toBe('call_8a1')
            ->and($stored[1]->tool_calls[0]['function']['name'])->toBe('lookup_invoice')
            ->and($stored[2]->tool_call_id)->toBe('call_8a1')
            ->and(json_decode($stored[2]->content, true)['invoice']['number'])->toBe('INV-4471');

        ask($this->user, 'And now?', $id);

        $replayed = sentChatBodies()[2]['messages'];

        expect(array_column($replayed, 'role'))->toBe(['system', 'user', 'assistant', 'tool', 'assistant', 'user', 'user'])
            ->and($replayed[2]['tool_calls'])->toBe($stored[1]->tool_calls)
            ->and($replayed[3]['tool_call_id'])->toBe('call_8a1')
            ->and($replayed[3]['content'])->toBe($stored[2]->content);
    });

    it('keeps the full result in tool_calls, linked to the conversation', function () {
        fakeChatSequence(
            toolCallsBody([['call_8a1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('Overdue.'),
        );

        $id = ask($this->user, 'Why is INV-4471 late?')->json('conversation_id');

        expect(ToolCall::sole()->conversation_id)->toBe($id);
    });

    it('stores a coherent exchange when a high-risk call pauses for approval', function () {
        $refund = new RefundTool;

        bindTools($refund);

        fakeChatSequence(
            toolCallsBody([['call_9', 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6]]]),
            chatBody('Never reached.'),
        );

        $id = ask($this->user, 'Refund the late fee please')->assertOk()->json('conversation_id');

        $stored = Message::orderBy('seq')->get();

        expect($stored->pluck('role')->all())->toBe(['user', 'assistant', 'tool', 'assistant'])
            ->and($stored[2]->tool_call_id)->toBe('call_9')
            ->and(json_decode($stored[2]->content, true))->toBe(['ok' => false, 'error' => 'awaiting approval'])
            ->and($stored[3]->content)->toStartWith('I need your approval before I can do this');

        fakeChatSequence(chatBody('Next.'));

        ask($this->user, 'Anything else?', $id)->assertOk();

        expect(array_column(sentChatBodies()[0]['messages'], 'role'))->toBe(['system', 'user', 'assistant', 'tool', 'assistant', 'user', 'user']);
    });
});

describe('summary and memories in the prompt', function () {
    it('puts the summary right after the system message and before the history', function () {
        fakeChatSequence(chatBody('Reply.'));

        $conversation = conversationFor($this->user);
        addExchange($conversation, 'Why is my March invoice late?', 'It is 18 days overdue.');
        $conversation->update(['summary' => 'Problem: late fee on INV-4471.', 'summary_through_seq' => 0]);

        ask($this->user, 'And now?', $conversation->id);

        $messages = sentChatBodies()[0]['messages'];

        expect(array_column($messages, 'role'))->toBe(['system', 'system', 'user', 'assistant', 'user', 'user'])
            ->and($messages[1]['content'])->toBe("Earlier in this conversation:\nProblem: late fee on INV-4471.");
    });

    it('injects the user\'s memories into the system message, keys in order', function () {
        UserMemory::create(['user_id' => $this->user->id, 'tenant_id' => $this->user->tenant_id, 'key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner', 'source' => 'stated']);
        UserMemory::create(['user_id' => $this->user->id, 'tenant_id' => $this->user->tenant_id, 'key' => 'billing_contact', 'value' => 'billing@acme.example', 'source' => 'stated']);

        fakeChatSequence(chatBody('Hello.'));

        ask($this->user, 'Hi');

        $system = sentChatBodies()[0]['messages'][0]['content'];

        expect($system)->toStartWith(SupportPrompt::system())
            ->and($system)->toEndWith("What you know about this customer (they can edit this in Settings):\n- billing_contact: billing@acme.example\n- role: bookkeeper for Acme Studios, not the owner");
    });

    it('does not send another user\'s memories', function () {
        $other = User::factory()->create();

        UserMemory::create(['user_id' => $other->id, 'tenant_id' => $other->tenant_id, 'key' => 'role', 'value' => 'secret-beta-9920', 'source' => 'stated']);

        fakeChatSequence(chatBody('Hello.'));

        ask($this->user, 'Hi');

        expect(sentChatBodies()[0]['messages'][0]['content'])->toBe(SupportPrompt::system());
    });

    it('records that the memories were used', function () {
        $memory = UserMemory::create(['user_id' => $this->user->id, 'tenant_id' => $this->user->tenant_id, 'key' => 'role', 'value' => 'bookkeeper', 'source' => 'stated']);

        fakeChatSequence(chatBody('Hello.'));

        ask($this->user, 'Hi');

        expect($memory->fresh()->last_used_at)->not->toBeNull();
    });
});

describe('a long conversation', function () {
    function fakeLongConversation(): void
    {
        Http::fake(['*/chat/completions' => function (Request $request) {
            $system = $request['messages'][0]['content'];

            if (str_starts_with($system, 'You maintain a running summary')) {
                return Http::response(chatBody("Problem: bookkeeper at Acme asks about INV-4471 (March, \$1,284.50).\nEstablished: client claims payment on 2026-03-03.\nTried: lookup_invoice. Open: waiver."));
            }

            return Http::response(chatBody('Answer: '.str_repeat('y', 300), usage: ['prompt_tokens' => 1000, 'completion_tokens' => 80]));
        }]);
    }

    function promptSize(array $body): int
    {
        return (new RatioCounter)->count(collect($body['messages'])->map(fn (array $m) => ($m['content'] ?? '').json_encode($m['tool_calls'] ?? ''))->implode("\n"));
    }

    it('flattens the prompt after the allowance fills, summarizes, and still knows turn 1 on turn 31', function () {
        fakeLongConversation();

        $this->withoutMiddleware(ThrottleRequests::class);

        $questions = collect(json_decode(file_get_contents(base_path('storage/samples/long-conversation.json')), true)['messages']);

        $id = null;
        $sizes = [];
        $firstSummaryTurn = null;

        foreach ($questions as $index => $question) {
            $id = ask($this->user, $question.' '.str_repeat('z', 300), $id)->assertOk()->json('conversation_id');

            $sizes[$index + 1] = promptSize(collect(sentChatBodies())->filter(fn ($b) => ! str_starts_with($b['messages'][0]['content'], 'You maintain'))->last());

            if ($firstSummaryTurn === null && Conversation::find($id)->summary !== null) {
                $firstSummaryTurn = $index + 1;
            }
        }

        expect($firstSummaryTurn)->not->toBeNull()
            ->and($firstSummaryTurn)->toBeLessThan(25)
            ->and($sizes[2])->toBeLessThan($sizes[10])
            ->and($sizes[10])->toBeGreaterThan($sizes[1]);

        $allowance = config('ai.budget.window') - config('ai.budget.answer');

        $peakBeforeSummary = max(array_slice($sizes, 0, $firstSummaryTurn, preserve_keys: true));

        expect(max($sizes))->toBeLessThan($allowance)                                // never overflows the window
            ->and(max($sizes))->toBe($peakBeforeSummary)                              // and never climbs past where the first summary was written
            ->and($sizes[$firstSummaryTurn + 1])->toBeLessThan($sizes[$firstSummaryTurn]);   // the summary replaces old turns

        $conversation = Conversation::find($id);

        expect($conversation->summary)->toContain('INV-4471')
            ->and($conversation->summary_through_seq)->toBeGreaterThan(0);

        ask($this->user, 'Going back to the first invoice I mentioned, is that one sorted now?', $id);

        $last = collect(sentChatBodies())->filter(fn ($b) => ! str_starts_with($b['messages'][0]['content'], 'You maintain'))->last();

        expect(json_encode($last['messages']))->toContain('INV-4471')                // the pinned first exchange and the summary both carry it
            ->and($last['messages'][0]['role'])->toBe('system')
            ->and($last['messages'][1]['content'])->toStartWith('Earlier in this conversation:');
    });
});

it('stores the conversation under the tenant of the user, on the real app container', function () {
    $store = app(ConversationStore::class);

    $conversation = $store->start($this->user, SupportPrompt::VERSION);

    expect(Tenant::find($conversation->tenant_id)->customer_id)->toBe($this->user->customer_id);
});
