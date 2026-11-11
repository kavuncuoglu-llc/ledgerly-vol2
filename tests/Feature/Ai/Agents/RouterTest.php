<?php

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Schemas\Category;
use App\Ai\Support\ClassifyTicket;
use App\Ai\Support\Route;
use App\Ai\Support\Router;
use App\Ai\Support\Votes;
use App\Models\CachedAnswer;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function votesFor(string ...$labels): void
{
    Http::swap(new Factory);

    fakeChatSequence(...array_map(fn (string $label) => chatBody(ticketJson($label)), $labels));
}

beforeEach(function () {
    Sleep::fake();

    $this->user = User::factory()->owner()->create();
});

describe('Votes', function () {
    it('takes the majority and measures the agreement', function () {
        $votes = new Votes(['billing', 'billing', 'bug']);

        expect($votes->majority())->toBe(Category::Billing)
            ->and($votes->agreement())->toEqualWithDelta(0.6667, 0.0001)
            ->and($votes->distinct())->toBe(['billing', 'bug']);
    });

    it('is unanimous or empty', function () {
        expect((new Votes(['bug', 'bug', 'bug']))->agreement())->toBe(1.0)
            ->and((new Votes([]))->majority())->toBeNull()
            ->and((new Votes([]))->agreement())->toBe(0.0)
            ->and((new Votes([]))->isEmpty())->toBeTrue();
    });

    it('breaks a tie with the label that was voted first', function () {
        expect((new Votes(['bug', 'billing']))->majority())->toBe(Category::Bug);
    });
});

describe('ClassifyTicket::sample', function () {
    it('asks the classifier n times at the assistant temperature, so a split vote can show', function () {
        votesFor('billing', 'billing', 'bug');

        $votes = app(ClassifyTicket::class)->sample('Why was I charged?', 3);

        $bodies = sentChatBodies();

        expect($votes->labels())->toBe(['billing', 'billing', 'bug'])
            ->and($bodies)->toHaveCount(3)
            ->and($bodies[0]['temperature'])->toBe(0.7)
            ->and(isset($bodies[0]['seed']))->toBeFalse()
            ->and(ModelCall::where('feature', 'ticket.classify')->count())->toBe(3);
    });

    it('loses a vote, not the ticket, when the model fails once', function () {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(chatBody(ticketJson('billing')))
            ->push('down', 500)
            ->push('down', 500)
            ->push('down', 500)
            ->push(chatBody(ticketJson('billing')))]);

        $votes = app(ClassifyTicket::class)->sample('Why was I charged?', 3);

        expect(count($votes->labels()))->toBeLessThan(3);
    });
});

describe('Router', function () {
    it('gives a billing question the invoice tools and the billing articles', function () {
        votesFor('billing', 'billing', 'billing');

        $route = app(Router::class)->route('Why was I charged a late fee on invoice 4471?', $this->user);

        expect($route->kind)->toBe(Route::AUTOMATE)
            ->and($route->category)->toBe(Category::Billing)
            ->and($route->agreement)->toBe(1.0)
            ->and($route->tools)->toBe(['lookup_invoice', 'list_invoices', 'check_payment_status', 'resend_invoices', 'retry_payment', 'issue_credit', 'open_ticket'])
            ->and($route->area)->toBe('billing')
            ->and($route->cache)->toBeFalse()
            ->and($route->votes)->toBe(['billing', 'billing', 'billing']);
    });

    it('routes each category as the chapter says', function (string $label, array $tools, ?string $area) {
        votesFor($label, $label, $label);

        $route = app(Router::class)->route('A question', $this->user);

        expect($route->tools)->toBe($tools)
            ->and($route->area)->toBe($area);
    })->with([
        'howto' => ['howto', ['open_ticket'], null],
        'bug' => ['bug', ['open_ticket'], 'troubleshooting'],
        'account' => ['account', ['account_status', 'change_billing_email', 'open_ticket'], 'account'],
    ]);

    it('lets a how-to question use the cache, and escalates a bug after two rounds', function () {
        votesFor('howto', 'howto', 'howto');

        expect(app(Router::class)->route('How do I add a logo?', $this->user)->cache)->toBeTrue();

        votesFor('bug', 'bug', 'bug');

        expect(app(Router::class)->route('The PDF button does nothing', $this->user)->escalateAfterRounds)->toBe(2);
    });

    it('declines what is not a Ledgerly question', function () {
        votesFor('other', 'other', 'other');

        $route = app(Router::class)->route('Can you recommend an accountant?', $this->user);

        expect($route->isDecline())->toBeTrue()
            ->and($route->tools)->toBe([]);
    });

    it('sends a 2-1 split to a person with both labels, as Figure 17-8 does for #3', function () {
        votesFor('bug', 'billing', 'bug');

        $route = app(Router::class)->route('The invoice page is broken and I was charged', $this->user);

        expect($route->isHuman())->toBeTrue()
            ->and($route->reason)->toBe('ambiguous category: bug/billing')
            ->and($route->category)->toBe(Category::Bug)
            ->and($route->agreement)->toEqualWithDelta(0.6667, 0.0001);
    });

    it('sends a three-way split to a person with every label', function () {
        votesFor('bug', 'billing', 'account');

        $route = app(Router::class)->route('Everything is wrong', $this->user);

        expect($route->isHuman())->toBeTrue()
            ->and($route->reason)->toBe('ambiguous category: bug/billing/account')
            ->and($route->candidates)->toBe(['bug', 'billing', 'account']);
    });

    it('falls back to every tool when the classifier gives nothing at all', function () {
        Http::fake(['*/chat/completions' => Http::response('down', 500)]);

        $route = app(Router::class)->route('Anything', $this->user);

        expect($route->tools)->toBeNull()
            ->and($route->category)->toBeNull();
    });

    it('is off unless configured on', function () {
        expect(app(Router::class)->enabled())->toBeFalse();

        config(['ai.router.enabled' => true]);

        expect(app(Router::class)->enabled())->toBeTrue();
    });
});

describe('the router in front of the support assistant', function () {
    beforeEach(function () {
        config(['ai.router.enabled' => true, 'ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);

        [$this->user] = customerWithInvoice();
    });

    function askRouted(User $user, string $question, ?int $conversationId = null)
    {
        return askAssistant($user, array_filter(['question' => $question, 'conversation_id' => $conversationId], fn ($v) => $v !== null));
    }

    function routedWorld(array $votes, string $answer = 'Open the invoice and check the status.'): void
    {
        $classify = 0;

        Http::fake([
            '*/embeddings' => fn ($request) => embeddingsResponse($request),
            '*/chat/completions' => function ($request) use ($votes, $answer, &$classify) {
                if (str_starts_with((string) $request['messages'][0]['content'], 'You classify Ledgerly')) {
                    return Http::response(chatBody(ticketJson($votes[min($classify++, count($votes) - 1)])));
                }

                return Http::response(chatBody($answer));
            },
        ]);
    }

    function mainCalls(): array
    {
        return array_values(array_filter(sentChatBodies(), fn (array $body) => ! str_starts_with((string) $body['messages'][0]['content'], 'You classify Ledgerly')));
    }

    it('offers only the tools of the category, in the main call', function () {
        routedWorld(['billing', 'billing', 'billing']);

        askRouted($this->user, 'Why was I charged a late fee on invoice 4471?')->assertOk();

        expect(collect(mainCalls()[0]['tools'])->pluck('function.name')->all())->toBe(['check_payment_status', 'issue_credit', 'list_invoices', 'lookup_invoice', 'open_ticket', 'resend_invoices', 'retry_payment']);
    });

    it('sends no tools key at all for a category with none, rather than an empty list', function () {
        routedWorld(['other', 'other', 'other']);

        chunkRow(['content' => 'Billing text about invoices and statuses and fees that mentions a pizza.']);

        askRouted($this->user, 'Which pizza fees are on invoices?')->assertOk();

        expect(isset(mainCalls()[0]['tools']))->toBeFalse();
    });

    it('declines a non-Ledgerly question without calling the main model when the help center has nothing', function () {
        routedWorld(['other', 'other', 'other']);

        $response = askRouted($this->user, 'Can you recommend an accountant?')->assertOk();

        expect($response->json('answer'))->toBe(SupportPrompt::declineMessage())
            ->and(mainCalls())->toBe([])
            ->and(Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->value('content'))->toBe(SupportPrompt::declineMessage());
    });

    it('lets the main model answer a question the classifier called other when the help center does cover it', function () {
        routedWorld(['other', 'other', 'other'], 'A late fee is applied automatically 30 days after the due date [late-fees › When a late fee is applied].');

        chunkRow(['content' => 'A late fee is applied automatically 30 days after the due date unless the invoice is disputed.', 'heading' => 'Late fees › When a late fee is applied']);

        $response = askRouted($this->user, 'When is a late fee applied after the due date?')->assertOk();

        expect(count(mainCalls()))->toBe(1)
            ->and($response->json('sources'))->toHaveCount(1);
    });

    it('widens the area filter once when the classifier\'s area has nothing', function () {
        routedWorld(['billing', 'billing', 'billing'], 'I can\'t find that in the help center, so I can open a support ticket.');

        chunkRow(['content' => 'Reminders go out three, seven and fourteen days after the due date.', 'tags' => ['area' => 'reminders'], 'source' => 'help/reminders/schedule.md', 'heading' => 'Reminders › Schedule']);

        askRouted($this->user, 'When do reminders go out after the due date?')->assertOk();

        $context = collect(mainCalls()[0]['messages'])->pluck('content')->first(fn ($c) => str_contains((string) $c, 'The following passages are from'));

        expect($context)->toContain('fourteen days');
    });

    it('treats a split vote as every tool, not as a refusal, in an attended chat', function () {
        routedWorld(['bug', 'billing', 'account']);

        askRouted($this->user, 'Everything is wrong')->assertOk();

        expect(collect(mainCalls()[0]['tools'])->pluck('function.name')->all())->toBe(['account_status', 'change_billing_email', 'check_payment_status', 'issue_credit', 'list_invoices', 'lookup_invoice', 'open_ticket', 'resend_invoices', 'retry_payment']);
    });

    it('routes only the first question of a conversation', function () {
        routedWorld(['billing', 'billing', 'billing']);

        $first = askRouted($this->user, 'Why was I charged a late fee on invoice 4471?')->assertOk();

        $classifications = fn () => count(sentChatBodies()) - count(mainCalls());

        expect($classifications())->toBe(3);

        askRouted($this->user, 'And what about the next one?', $first->json('conversation_id'))->assertOk();

        expect($classifications())->toBe(3)
            ->and(collect(mainCalls()[1]['tools'])->pluck('function.name')->all())->toBe(['account_status', 'change_billing_email', 'check_payment_status', 'issue_credit', 'list_invoices', 'lookup_invoice', 'open_ticket', 'resend_invoices', 'retry_payment']);
    });

    it('still answers when the classifier is down', function () {
        config(['ai.breaker.threshold' => 100]);                                // Chapter 25: nine failures in a row would open the breaker; this test is about the classifier alone

        Http::fake(['*/chat/completions' => Http::sequence()->push('down', 500)->push('down', 500)->push('down', 500)->push('down', 500)->push('down', 500)->push('down', 500)->push('down', 500)->push('down', 500)->push('down', 500)->push(chatBody('Open the invoice.'))]);

        askRouted($this->user, 'How do I add a logo?')->assertOk();
    });

    it('reuses the router\'s category instead of classifying again when it stores a cacheable answer', function () {
        config(['ai.semantic_cache.enabled' => true, 'ai.semantic_cache.require_context' => false]);

        routedWorld(['howto', 'howto', 'howto'], 'Open the invoice and choose Make recurring.');

        askRouted($this->user, 'How do I set up recurring invoices?')->assertOk();

        $classifications = count(sentChatBodies()) - count(mainCalls());

        expect($classifications)->toBe(3)                                       // the three votes; no fourth call to label the answer
            ->and(CachedAnswer::sole()->category)->toBe('howto');
    });
});
