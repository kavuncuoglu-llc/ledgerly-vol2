<?php

use App\Ai\Cache\HelpVersion;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RefundTool;

const RECURRING = 'How do I set up recurring invoices?';
const REPHRASED = 'Can I make an invoice repeat every month?';
const OTHER = 'How do I add a new client?';

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.semantic_cache.enabled' => true, 'ai.semantic_cache.require_context' => false]);

    [$this->user] = customerWithInvoice();

    $this->angles = [RECURRING => 0, REPHRASED => 15, OTHER => 90];
});

function askSupport(User $user, string $question, ?int $conversationId = null)
{
    return askAssistant($user, array_filter(['question' => $question, 'conversation_id' => $conversationId], fn ($v) => $v !== null));
}

function chatCalls(): int
{
    return count(sentChatBodies());
}

function embeddingCalls(): int
{
    return Http::recorded()->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/embeddings'))->count();
}

it('answers a repeated how-to question from the cache without calling the model', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice, choose Make recurring, and set the interval.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING)->assertOk()->assertJsonMissing(['semantic_cache']);

    expect(CachedAnswer::sole()->answer)->toBe('Open the invoice, choose Make recurring, and set the interval.')
        ->and(chatCalls())->toBe(2);                                                  // the answer, then the classifier

    $response = askSupport($this->user, RECURRING)->assertOk();

    expect($response->json('answer'))->toBe('Open the invoice, choose Make recurring, and set the interval.')
        ->and($response->json('semantic_cache'))->toBe(['similarity' => 1, 'question' => RECURRING])
        ->and(chatCalls())->toBe(2)                                                   // nothing new
        ->and(CachedAnswer::sole()->hit_count)->toBe(1);
});

it('still hits when the question is rephrased', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice, choose Make recurring.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);

    $response = askSupport($this->user, REPHRASED)->assertOk();

    expect($response->json('answer'))->toBe('Open the invoice, choose Make recurring.')
        ->and($response->json('semantic_cache.similarity'))->toBe(0.97)
        ->and($response->json('semantic_cache.question'))->toBe(RECURRING)
        ->and(chatCalls())->toBe(2);
});

it('misses a different how-to question and calls the model', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.'), classifierReply('howto'), chatBody('Go to Clients, then New.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);
    askSupport($this->user, OTHER)->assertOk()->assertJsonPath('answer', 'Go to Clients, then New.');

    expect(CachedAnswer::count())->toBe(2)
        ->and(chatCalls())->toBe(4);
});

it('logs the hit as a model_calls row with no tokens, and the miss as an eligible miss', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.', usage: ['prompt_tokens' => 640, 'completion_tokens' => 9]), classifierReply('howto')]);

    askSupport($this->user, RECURRING);
    askSupport($this->user, RECURRING);

    $calls = ModelCall::where('feature', 'support.reply')->orderBy('id')->get();

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->semantic_cache_hit)->toBeFalse()
        ->and($calls[0]->prompt_tokens)->toBe(640)
        ->and($calls[1]->semantic_cache_hit)->toBeTrue()
        ->and($calls[1]->model)->toBe('semantic-cache')
        ->and($calls[1]->finish_reason)->toBe('cache')
        ->and($calls[1]->prompt_tokens)->toBe(0)
        ->and($calls[1]->cost_micros)->toBe(0);
});

it('stores the exchange in the conversation, so the next turn has it', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);

    $id = askSupport($this->user, RECURRING)->json('conversation_id');

    $messages = Message::where('conversation_id', $id)->orderBy('seq')->get();

    expect($messages->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($messages[0]->content)->toBe(RECURRING)
        ->and($messages[1]->content)->toBe('Open the invoice.')
        ->and($messages[1]->model_call_id)->toBe(ModelCall::where('semantic_cache_hit', true)->sole()->id);
});

it('does not even embed an account-specific question', function () {
    fakeCacheWorld($this->angles, [chatBody('It is 18 days overdue.')]);

    askSupport($this->user, 'Why is my March invoice late?')->assertOk();

    expect(embeddingCalls())->toBe(0)
        ->and(chatCalls())->toBe(1)
        ->and(CachedAnswer::count())->toBe(0)
        ->and(ModelCall::sole()->semantic_cache_hit)->toBeNull();
});

it('only answers a first question from the cache: a follow-up leans on the conversation', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.'), classifierReply('howto'), chatBody('Go to Clients.'), classifierReply('howto'), chatBody('Fresh answer.')]);

    askSupport($this->user, RECURRING);

    $id = askSupport($this->user, OTHER)->json('conversation_id');
    $before = chatCalls();

    askSupport($this->user, RECURRING, $id)->assertOk()->assertJsonPath('answer', 'Fresh answer.');

    expect(chatCalls())->toBeGreaterThan($before);
});

it('does not store an answer that sends the customer to a ticket', function () {
    fakeCacheWorld($this->angles, [chatBody('That depends on your account, but I can open a support ticket for you.')]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(CachedAnswer::count())->toBe(0)
        ->and(chatCalls())->toBe(1);                                                  // and no classifier call was spent on it
});

it('does not store an answer that needed a tool', function () {
    fakeCacheWorld($this->angles, [
        toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
        chatBody('INV-4471 is overdue.'),
    ]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(CachedAnswer::count())->toBe(0);
});

it('does not store a reply that was cut off', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the', finish: 'length')]);

    askSupport($this->user, RECURRING)->assertOk()->assertJson(['truncated' => true]);

    expect(CachedAnswer::count())->toBe(0);
});

it('does not store an answer that waits for approval', function () {
    bindTools(new RefundTool);

    fakeCacheWorld($this->angles, [toolCallsBody([['call_9', 'issue_refund', ['invoice_number' => '4471', 'amount' => 21.6]]])]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(CachedAnswer::count())->toBe(0);
});

it('does not store when the classifier says the question is not a how-to', function () {
    fakeCacheWorld($this->angles, [chatBody('Your plan includes five seats.'), classifierReply('account')]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(CachedAnswer::count())->toBe(0);
});

it('answers anyway when the classifier fails', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.'), chatBody('not json at all')]);

    askSupport($this->user, RECURRING)->assertOk()->assertJsonPath('answer', 'Open the invoice.');

    expect(CachedAnswer::count())->toBe(0);
});

it('answers anyway when the embeddings endpoint is down', function () {
    Http::swap(new Factory);

    Http::fake([
        '*/embeddings' => Http::response('down', 503),
        '*/chat/completions' => Http::sequence()->push(chatBody('Open the invoice.'))->push(classifierReply('howto')),
    ]);

    askSupport($this->user, RECURRING)->assertOk()->assertJsonPath('answer', 'Open the invoice.');

    expect(CachedAnswer::count())->toBe(0);
});

it('stores only grounded answers once Chapter 15 turns require_context on', function () {
    config(['ai.semantic_cache.require_context' => true]);

    fakeCacheWorld($this->angles, [chatBody('Open the invoice.')]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(CachedAnswer::count())->toBe(0);
});

it('does nothing at all when the cache is switched off', function () {
    config(['ai.semantic_cache.enabled' => false]);

    fakeCacheWorld($this->angles, [chatBody('Open the invoice.')]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(embeddingCalls())->toBe(0)
        ->and(ModelCall::sole()->semantic_cache_hit)->toBeNull();
});

it('does not serve an answer written for another version of the help center', function () {
    fakeCacheWorld($this->angles, [chatBody('Old answer.'), classifierReply('howto'), chatBody('New answer.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);

    config(['ledgerly.help_version' => 'v2']);

    app(HelpVersion::class)->forget();

    askSupport($this->user, RECURRING)->assertOk()->assertJsonPath('answer', 'New answer.');

    expect(CachedAnswer::count())->toBe(2);
});

it('serves a human-approved answer in preference to nothing, and never replaces it', function () {
    fakeCacheWorld($this->angles, [chatBody('Model answer.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);

    CachedAnswer::query()->update(['status' => 'approved', 'answer' => 'Reviewed answer.']);

    askSupport($this->user, REPHRASED)->assertJsonPath('answer', 'Reviewed answer.');
});

it('shares a how-to answer between tenants', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);

    [$other] = customerWithInvoice('INV-9001');

    askSupport($other, RECURRING)->assertJsonPath('answer', 'Open the invoice.');

    expect(CachedAnswer::sole()->tenant_id)->toBeNull()
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(2);
});

it('sends the context breakdown with the miss, and not to the model server', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice.'), classifierReply('howto')]);

    askSupport($this->user, RECURRING);

    $breakdown = ModelCall::where('feature', 'support.reply')->sole()->context_breakdown;

    expect(array_keys($breakdown))->toBe(['system', 'context', 'question'])
        ->and($breakdown['system'])->toBeGreaterThan(200);

    Http::assertSent(fn ($request) => ! array_key_exists('context_breakdown', $request->data()) && ! array_key_exists('semantic_cache_hit', $request->data()));
});

it('keeps the asking user\'s memories out of a question whose answer may be cached for others', function () {
    fakeCacheWorld($this->angles, [chatBody('Open the invoice, choose Make recurring.'), classifierReply('howto')]);

    UserMemory::query()->create([
        'user_id' => $this->user->id,
        'key' => 'business',
        'value' => 'Harbour Bakery on the Pro plan',
        'tenant_id' => $this->user->tenant_id,
        'source' => 'stated',
    ]);

    askSupport($this->user, RECURRING)->assertOk();

    expect(json_encode(sentChatBodies()[0]['messages']))->not->toContain('Harbour Bakery')
        ->and(CachedAnswer::sole()->answer)->not->toContain('Harbour Bakery');
});
