<?php

use App\Ai\Contracts\FinishReason;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Streaming\ReplyStarter;
use App\Ai\Structured\StructuredClient;
use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Support\SupportAssistant;
use App\Events\TicketReceived;
use App\Facades\Ai;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

// Chapter 29, Figure 29-2: the failure paths of the AI code, tested with the model replaced by Ai::fake(). No model, no HTTP, no GPU.
function askAs(User $user, string $question): Message
{
    test()->actingAs($user);

    return app(ReplyStarter::class)->start($user, $question)->fresh();
}

beforeEach(function () {
    Sleep::fake();

    Event::fake([TicketReceived::class]);

    [$this->user, $this->invoice, $this->customer] = customerWithInvoice();
});

it('flags a truncated answer instead of showing it', function () {
    Ai::fake()->for('support.reply')->respondWith('The late fee is calculated as', finishReason: FinishReason::Length);

    $message = askAs($this->user, 'How is the late fee calculated?');

    expect($message->status)->toBe('truncated')
        ->and($message->content)->toBe(SupportPrompt::truncatedMessage())
        ->and($message->content)->not->toContain('calculated as');
});

it('never replays a truncated answer to the model as if it had said it', function () {
    Ai::fake()->for('support.reply')->respondWith('The late fee is calculated as', finishReason: FinishReason::Length);

    $message = askAs($this->user, 'How is the late fee calculated?');

    $history = app(ConversationStore::class)->history($message->conversation, 4000);

    expect(json_encode($history))->not->toContain('calculated as')
        ->and(json_encode($history))->not->toContain('cut off');
});

it('gives up after one repair and raises, instead of looping', function () {
    Ai::fake()->for('invoice.extract')->respondWithJson(northgateInvoice(['total' => 1204.50]), times: 2);          // well-formed, but the total is wrong, twice

    try {
        app(StructuredClient::class)->extract(InvoiceData::class, [['role' => 'user', 'content' => 'read this']], [], 'invoice.extract');
    } catch (StructuredOutputFailed $e) {
        expect($e->attempts)->toHaveCount(2);

        Ai::assertSentCount('invoice.extract', 2);                                  // first try + one repair, no more

        return;
    }

    $this->fail('StructuredOutputFailed was not thrown');
});

it('never runs a tool the gate denies', function () {
    $viewer = User::factory()->owner($this->customer)->create(['role' => 'viewer']);

    Ai::fake()->for('support.reply')
        ->respondWithToolCall('open_ticket', ['summary' => 'Refund every one of my invoices.', 'invoice_number' => null])
        ->respondWith('I cannot do that here.');

    askAs($viewer, 'Please refund all my invoices');

    expect(ToolCall::where('tool', 'open_ticket')->value('decision'))->toBe('denied')
        ->and(Ticket::count())->toBe(0);
});

it('never hands a tool another customer\'s invoice', function () {
    [, $other] = customerWithInvoice('INV-9001');

    Ai::fake()->for('support.reply')
        ->respondWithToolCall('lookup_invoice', ['invoice_number' => 'INV-9001', 'month' => null])
        ->respondWith('I could not find that invoice on your account.');

    askAs($this->user, 'Show me INV-9001');

    $result = ToolCall::where('tool', 'lookup_invoice')->sole();

    expect($result->result)->toMatchArray(['found' => false])
        ->and(json_encode($result->result))->not->toContain((string) $other->total);
});

it('sends the escape-hatch reply when the answer cites a passage that was not retrieved, twice', function () {
    Ai::fake()->for('support.reply')->respondWith('The fee is 99% of the balance [late-fees › Made up].', times: 2);

    $user = $this->user;

    $result = app(SupportAssistant::class)->answer($user, 'How is the late fee calculated?', contextChunks: [retrievedChunk()]);

    expect($result->answer())->toBe(SupportPrompt::escapeHatch());

    Ai::assertSentCount('support.reply', 2);                                          // the answer and one repair
});

it('tells the customer the assistant is unavailable when the provider fails, and the job still succeeds', function () {
    Ai::fake()->for('support.reply')->respondWithError(new ConnectionException('connection refused'));

    $message = askAs($this->user, 'How is the late fee calculated?');

    expect($message->status)->toBe('failed')
        ->and($message->content)->toBe(SupportPrompt::unavailableMessage());
});

it('records what the model was shown, so a gate or a shadow run can look at the same passages', function () {
    Ai::fake()->for('support.reply')->respondWith('Fees start after a 30 day grace period. [late-fees › How the fee is calculated]');

    app(SupportAssistant::class)->answer($this->user, 'When do late fees start?', contextChunks: [retrievedChunk()]);

    $reply = Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->first();

    expect($reply->retrievedLabels())->toBe(['help:billing/late-fees#how-the-fee-is-calculated'])
        ->and($reply->retrieved[0]['label'])->toBe('late-fees › How the fee is calculated');
});
