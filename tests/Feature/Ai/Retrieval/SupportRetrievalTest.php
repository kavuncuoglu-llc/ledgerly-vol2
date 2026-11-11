<?php

use App\Ai\Cache\HelpVersion;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\PgRetriever;
use App\Ai\Security\Untrusted;
use App\Models\CachedAnswer;
use App\Models\HelpChunk;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\ModelCall;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const LATE_FEE_QUESTION = 'When is a late fee applied after the due date?';
const LATE_FEE_LABEL = 'late-fees › When a late fee is applied';
const GOOD_ANSWER = 'A late fee is applied automatically 30 days after the due date [late-fees › When a late fee is applied].';

function retrievalWorld(array $chatBodies): void
{
    $sequence = Http::sequence();

    foreach ($chatBodies as $body) {
        $sequence->push($body);
    }

    Http::fake([
        '*/embeddings' => fn (Request $request) => Http::response([
            'data' => [['embedding' => fakeEmbeddingVector($request['input'])]],
        ]),
        '*/chat/completions' => $sequence,
    ]);
}

function askRetrieval(User $user, string $question = LATE_FEE_QUESTION, ?int $conversationId = null)
{
    return askAssistant($user, array_filter(['question' => $question, 'conversation_id' => $conversationId], fn ($v) => $v !== null));
}

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.semantic_cache.enabled' => true, 'ai.retrieval.threshold' => 0.5]);

    $this->feeChunk = chunkRow([
        'content' => "Late fees › When a late fee is applied\nA late fee is applied automatically 30 days after the due date unless the invoice is disputed or a payment plan is active.",
        'heading' => 'Late fees › When a late fee is applied',
    ]);

    [$this->user, , $this->customer] = customerWithInvoice();
});

it('grounds the reply in the retrieved passage and returns the sources it cited', function () {
    retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

    $response = askRetrieval($this->user)->assertOk();

    expect($response->json('answer'))->toBe(GOOD_ANSWER)
        ->and($response->json('citations_ok'))->toBeTrue()
        ->and($response->json('sources'))->toBe([['label' => LATE_FEE_LABEL, 'url' => '/help/billing/late-fees#when-a-late-fee-is-applied']]);

    $sent = sentChatBodies()[0]['messages'];

    $context = collect($sent)->first(fn (array $m) => str_contains($m['content'] ?? '', 'The following passages are from'));

    expect($context['content'])->toContain('(version v1)')
        ->toContain('['.LATE_FEE_LABEL.']')
        ->toContain('30 days after the due date')
        ->and(end($sent)['content'])->toBe("Question:\n".Untrusted::wrap(LATE_FEE_QUESTION, 'customer_message'));
});

it('records which passages the reply used, under the reply message, in the customer\'s tenant', function () {
    retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

    askRetrieval($this->user)->assertOk();

    $source = MessageSource::withoutGlobalScopes()->sole();
    $reply = Message::withoutGlobalScopes()->findOrFail($source->message_id);

    expect($reply->role)->toBe('assistant')
        ->and($reply->content)->toBe(GOOD_ANSWER)
        ->and($source->tenant_id)->toBe($this->user->tenant_id)
        ->and($source->label)->toBe(LATE_FEE_LABEL)
        ->and($source->help_chunk_id)->toBe($this->feeChunk->id)
        ->and($source->help_version)->toBe('v1')
        ->and($source->url)->toBe('/help/billing/late-fees#when-a-late-fee-is-applied');
});

it('logs the citation error count on the model call', function () {
    retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

    askRetrieval($this->user);

    expect(ModelCall::where('feature', 'support.reply')->orderBy('id')->first()->citation_errors)->toBe(0);
});

it('asks once more when the checker finds a wrong number, and shows the corrected answer', function () {
    retrievalWorld([
        chatBody('A late fee is applied automatically 90 days after the due date [late-fees › When a late fee is applied].'),
        chatBody(GOOD_ANSWER),
    ]);

    $response = askRetrieval($this->user)->assertOk();

    $bodies = sentChatBodies();

    $repair = end($bodies)['messages'];

    expect($response->json('answer'))->toBe(GOOD_ANSWER)
        ->and($response->json('citations_ok'))->toBeTrue()
        ->and($bodies)->toHaveCount(2)
        ->and(end($repair)['role'])->toBe('user')
        ->and(end($repair)['content'])->toContain('citation problems')->toContain('shares no key terms or numbers')
        ->and(ModelCall::where('feature', 'support.reply')->orderBy('id')->pluck('citation_errors')->all())->toBe([1, 0])
        ->and(ModelCall::where('feature', 'support.reply')->orderBy('id')->pluck('repaired')->all())->toBe([false, true])
        ->and(MessageSource::withoutGlobalScopes()->count())->toBe(1);
});

it('does not cache a repaired answer', function () {
    retrievalWorld([
        chatBody('A late fee is applied automatically 90 days after the due date [late-fees › When a late fee is applied].'),
        chatBody(GOOD_ANSWER),
    ]);

    askRetrieval($this->user)->assertOk();

    expect(CachedAnswer::count())->toBe(0);
});

it('falls back to the escape hatch when the second attempt is wrong too, and shows no sources', function () {
    $wrong = chatBody('A late fee is applied automatically 90 days after the due date [late-fees › When a late fee is applied].');

    retrievalWorld([$wrong, $wrong]);

    $response = askRetrieval($this->user)->assertOk();

    expect($response->json('answer'))->toBe(SupportPrompt::escapeHatch())
        ->and($response->json('citations_ok'))->toBeFalse()
        ->and($response->json('sources'))->toBe([])
        ->and(MessageSource::withoutGlobalScopes()->count())->toBe(0)
        ->and(CachedAnswer::count())->toBe(0)
        ->and(Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->value('content'))->toBe(SupportPrompt::escapeHatch());
});

it('flags a fabricated label and repairs it', function () {
    retrievalWorld([
        chatBody('A late fee is applied automatically 30 days after the due date [invented › Section].'),
        chatBody(GOOD_ANSWER),
    ]);

    askRetrieval($this->user)->assertOk()->assertJsonPath('answer', GOOD_ANSWER);

    expect(ModelCall::where('feature', 'support.reply')->orderBy('id')->first()->citation_errors)->toBe(1);
});

it('answers from nothing but the escape hatch when the help center has no passage close enough', function () {
    config(['ai.retrieval.threshold' => 0.95]);

    app()->forgetInstance(PgRetriever::class);

    retrievalWorld([chatBody('I can\'t find that in the help center, so I can open a support ticket.')]);

    $response = askRetrieval($this->user, 'Where can I find the best pizza near the office?')->assertOk();

    $context = collect(sentChatBodies()[0]['messages'])->pluck('content')->first(fn ($c) => str_starts_with((string) $c, 'Context:'));

    expect($context)->toBe("Context:\n(no context provided)")
        ->and($response->json('sources'))->toBe([])
        ->and(CachedAnswer::count())->toBe(0);
});

it('still answers when the embedding server is down: retrieval fails soft', function () {
    Http::fake([
        '*/embeddings' => Http::response('down', 500),
        '*/chat/completions' => Http::response(chatBody('I can\'t find that in the help center, so I can open a support ticket.')),
    ]);

    $this->withoutExceptionHandling();

    askRetrieval($this->user)->assertOk()->assertJsonPath('sources', []);
});

it('checks only the labels when a tool produced the facts', function () {
    $lookup = toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => 'INV-4471']]]);

    retrievalWorld([$lookup, chatBody('INV-4471 is 18 days overdue with a late fee of $21.60.')]);

    $response = askRetrieval($this->user, 'Why is invoice INV-4471 marked late?')->assertOk();

    expect($response->json('citations_ok'))->toBeTrue()
        ->and($response->json('answer'))->toContain('18 days overdue');
});

describe('what may be shared through the FAQ cache', function () {
    it('stores a verified answer built only from shared help-center passages, and serves it to another tenant', function () {
        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

        askRetrieval($this->user)->assertOk();

        expect(CachedAnswer::sole()->tenant_id)->toBeNull()
            ->and(CachedAnswer::sole()->answer)->toBe(GOOD_ANSWER);

        [$other] = customerWithInvoice('INV-9001');

        $before = count(sentChatBodies());

        askRetrieval($other)->assertOk()->assertJsonPath('answer', GOOD_ANSWER);

        expect(count(sentChatBodies()))->toBe($before);
    });

    it('does not store an answer when nothing was retrieved (require_context is on)', function () {
        HelpChunk::query()->delete();

        retrievalWorld([chatBody('Open the invoice, choose Make recurring, and set the interval.'), classifierReply('howto')]);

        askRetrieval($this->user, 'How do I set up recurring invoices?')->assertOk();

        expect(CachedAnswer::count())->toBe(0);
    });

    it('does not store an answer whose passages were plan-restricted', function () {
        HelpChunk::query()->update(['tags' => ['area' => 'billing', 'plans' => ['pro', 'enterprise']]]);

        $this->customer->update(['plan' => 'pro']);

        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

        askRetrieval($this->user)->assertOk()->assertJsonPath('citations_ok', true);

        expect(CachedAnswer::count())->toBe(0);
    });

    it('does not store an answer whose passages were private to a tenant', function () {
        HelpChunk::query()->update(['tenant_id' => $this->user->tenant_id]);

        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

        askRetrieval($this->user)->assertOk()->assertJsonPath('citations_ok', true);

        expect(CachedAnswer::count())->toBe(0);
    });

    it('never puts the asker\'s memories in the prompt of a question that may be cached', function () {
        UserMemory::create(['user_id' => $this->user->id, 'tenant_id' => $this->user->tenant_id, 'key' => 'role', 'value' => 'secret-beta-9920 bookkeeper', 'source' => 'stated']);

        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

        askRetrieval($this->user)->assertOk();

        expect(json_encode(sentChatBodies()[0]))->not->toContain('secret-beta-9920');
    });

    it('does not cache a grounded answer for a question that mentions the account', function () {
        retrievalWorld([chatBody(GOOD_ANSWER)]);

        askRetrieval($this->user, 'When is a late fee applied to my invoice after the due date?')->assertOk();

        expect(CachedAnswer::count())->toBe(0);
    });

    it('does not serve a cached answer after the help center is re-ingested under a new version', function () {
        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto'), chatBody(GOOD_ANSWER), classifierReply('howto')]);

        askRetrieval($this->user)->assertOk();

        HelpChunk::query()->update(['help_version' => 'v2']);

        config(['ledgerly.help_version' => 'v2']);

        app(HelpVersion::class)->forget();

        $before = count(sentChatBodies());

        askRetrieval($this->user)->assertOk();

        expect(count(sentChatBodies()))->toBeGreaterThan($before);
    });
});

describe('tenant isolation of the new data paths', function () {
    it('keeps a tenant\'s private passage out of another tenant\'s prompt', function () {
        $acme = Tenant::factory()->create();

        chunkRow(['tenant_id' => $acme->id, 'source' => 'help/private/acme.md', 'heading' => 'Acme › Late fee exception', 'content' => 'Acme Studios has a negotiated late fee of 0.5% applied after 60 days, secret-terms-7731.']);

        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto'), chatBody(GOOD_ANSWER), classifierReply('howto')]);

        $acmeUser = User::factory()->inTenant($acme)->create();

        askRetrieval($acmeUser, 'What is the negotiated late fee for Acme Studios after 60 days?')->assertOk();

        expect(json_encode(sentChatBodies()[0]))->toContain('secret-terms-7731');

        askRetrieval($this->user, 'What is the negotiated late fee for Acme Studios after 60 days?')->assertOk();

        expect(json_encode(sentChatBodies()[1]))->not->toContain('secret-terms-7731');
    });

    it('lets a customer see only their own tenant\'s message_sources', function () {
        retrievalWorld([chatBody(GOOD_ANSWER), classifierReply('howto')]);

        askRetrieval($this->user)->assertOk();

        [$other] = customerWithInvoice('INV-9001');

        $this->actingAs($other);

        expect(MessageSource::count())->toBe(0);

        $this->actingAs($this->user);

        expect(MessageSource::count())->toBe(1);
    });

    it('refuses another tenant\'s conversation', function () {
        [$other] = customerWithInvoice('INV-9001');

        $conversation = conversationFor($other);

        retrievalWorld([chatBody(GOOD_ANSWER)]);

        askRetrieval($this->user, LATE_FEE_QUESTION, $conversation->id)->assertNotFound();
    });
});
