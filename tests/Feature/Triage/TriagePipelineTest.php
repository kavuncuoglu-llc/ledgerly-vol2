<?php

use App\Ai\Triage\TriagePipeline;
use App\Events\TicketReceived;
use App\Models\AgentRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function modelDownAfterVoting(): void
{
    Http::fake([
        '*/chat/completions' => fn ($request) => str_starts_with((string) $request['messages'][0]['content'], 'You classify')
            ? Http::response(chatBody(ticketJson('howto')))
            : Http::response('down', 500),
    ]);
}

function chatFeatures(): array
{
    return ModelCall::query()->orderBy('id')->pluck('feature')->all();
}

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);

    chunkRow([
        'source' => 'help/billing/late-fees.md',
        'heading' => 'Late fees › When a late fee is applied',
        'content' => 'A late fee is applied automatically 30 days after the due date unless the invoice is disputed.',
    ]);

    $this->customer = Customer::factory()->create(['plan' => 'pro']);
    $this->owner = User::factory()->owner($this->customer)->create();

    Invoice::factory()->for($this->customer)->overdue(18)->withLateFee()->create(['number' => 'INV-4471', 'subtotal' => 1200, 'tax' => 84.50, 'total' => 1284.50]);
    Invoice::factory()->for($this->customer)->overdue(41)->withLateFee()->create(['number' => 'INV-4438']);
});

describe('a billing ticket', function () {
    beforeEach(function () {
        $this->script = [
            'votes' => ['invoice 4471' => ['billing', 'billing', 'billing']],
            'extract' => ['invoice 4471' => ['invoice_number' => '4471', 'category' => 'billing']],
            'steps' => [
                toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
                toolCallsBody([['c2', 'list_invoices', ['status' => null, 'months' => null]]]),
                chatBody('INV-4471 is 18 days overdue with a late fee. Payment by the client is not recorded.'),
            ],
            'draft' => 'A late fee is applied automatically 30 days after the due date [late-fees › When a late fee is applied]. A specialist will check the payment.',
        ];

        $this->ticket = triageTicket(customer: $this->customer);
    });

    it('classifies by vote, extracts, investigates with the agent and queues a draft for review', function () {
        triageWorld($this->script);

        $result = app(TriagePipeline::class)->run($this->ticket);

        $run = $result->run->fresh();

        expect($result->human)->toBeFalse()
            ->and($run->status)->toBe('automated')
            ->and([$run->category, $run->agreement, $run->votes])->toBe(['billing', 1.0, ['billing', 'billing', 'billing']])
            ->and([$run->urgency, $run->assignee])->toBe(['normal', 'billing-team'])
            ->and($run->fields)->toMatchArray(['invoice_number' => '4471'])
            ->and($run->findings['invoices'][0])->toMatchArray(['number' => 'INV-4471', 'days_overdue' => 18])
            ->and($run->findings['invoices_listed'])->toBeGreaterThan(0)
            ->and($run->findings['summary'])->toContain('18 days overdue')
            ->and($run->findings['run'])->toBe(['status' => 'done', 'reason' => null, 'rounds' => 3])
            ->and($run->draft)->toContain('late fee is applied')
            ->and([$run->draft_status, $run->draft_citations_ok])->toBe(['queued', true])
            ->and($run->agent_run_id)->not->toBeNull();

        $ticket = $this->ticket->fresh();

        expect([$ticket->category, $ticket->urgency, $ticket->assignee])->toBe(['billing', 'normal', 'billing-team'])
            ->and($ticket->triaged_at)->not->toBeNull();
    });

    it('runs the model for each workflow step and the agent as exactly one bounded step', function () {
        triageWorld($this->script);

        app(TriagePipeline::class)->run($this->ticket);

        expect(chatFeatures())->toBe(['ticket.classify', 'ticket.classify', 'ticket.classify', 'ticket.extract', 'triage.investigate', 'triage.investigate', 'triage.investigate', 'triage.draft'])
            ->and(AgentRun::count())->toBe(1)
            ->and(AgentRun::sole()->triage_run_id)->toBe(TriageRun::sole()->id);
    });

    it('applies the urgency rule in PHP: the 41-day invoice makes it urgent', function () {
        $this->script['extract'] = ['invoice 4471' => ['invoice_number' => '4438', 'category' => 'billing']];

        triageWorld($this->script);

        $result = app(TriagePipeline::class)->run($this->ticket);

        expect($result->urgency->value)->toBe('urgent')
            ->and($this->ticket->fresh()->urgency)->toBe('urgent');
    });

    it('lists the draft in the review queue and never sends it', function () {
        triageWorld($this->script);

        app(TriagePipeline::class)->run($this->ticket);

        $this->artisan('triage:review')->expectsOutputToContain('1 drafts waiting')->assertSuccessful();

        $run = TriageRun::sole();

        $this->artisan("triage:review --show={$run->id}")->expectsOutputToContain('A late fee is applied automatically')->assertSuccessful();
        $this->artisan("triage:review --approve={$run->id}")->expectsOutputToContain('nothing was sent')->assertSuccessful();

        expect($run->fresh()->draft_status)->toBe('approved');

        $this->artisan("triage:review --approve={$run->id}")->assertFailed();
    });

    it('flags a draft whose citations do not check, instead of hiding it', function () {
        $this->script['draft'] = 'A late fee is applied automatically 90 days after the due date [late-fees › When a late fee is applied].';

        triageWorld($this->script);

        app(TriagePipeline::class)->run($this->ticket);

        $run = TriageRun::sole();

        expect($run->draft_status)->toBe('queued')
            ->and($run->draft_citations_ok)->toBeTrue();            // findings exist, so only fabricated labels are checked

        $this->artisan('triage:review')->expectsOutputToContain('cited')->assertSuccessful();
    });

    it('keeps what the agent found when it runs out of budget', function () {
        config(['ai.agents.triage.max_rounds' => 1]);

        triageWorld($this->script);

        $result = app(TriagePipeline::class)->run($this->ticket);

        expect($result->findings['run'])->toBe(['status' => 'exhausted', 'reason' => 'rounds', 'rounds' => 1])
            ->and($result->findings['invoices'][0]['number'])->toBe('INV-4471')
            ->and($result->run->status)->toBe('automated')
            ->and($result->draft)->not->toBeNull();
    });

    it('never opens a ticket for anyone but the ticket\'s own customer, and does not triage what it opens', function () {
        Event::fake([TicketReceived::class]);

        $this->script['steps'] = [
            toolCallsBody([['c1', 'open_ticket', ['summary' => 'Payment reconciliation for INV-4471', 'invoice_number' => '4471']]]),
            chatBody('Opened a follow-up ticket.'),
        ];

        triageWorld($this->script);

        $result = app(TriagePipeline::class)->run($this->ticket);

        $opened = Ticket::where('source', 'assistant')->sole();

        expect($opened->customer_id)->toBe($this->customer->id)
            ->and($result->findings['tickets'][0])->toBe(['number' => $opened->number, 'created' => true]);

        Event::assertNotDispatched(TicketReceived::class);
    });

    it('does not let a ticket from one customer read another customer\'s invoice', function () {
        $stranger = Customer::factory()->create();

        Invoice::factory()->for($stranger)->overdue(77)->create(['number' => 'INV-5555']);

        $this->script['extract'] = ['invoice 4471' => ['invoice_number' => '5555', 'category' => 'billing']];
        $this->script['steps'] = [
            toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '5555', 'month' => null]]]),
            chatBody('Could not find that invoice.'),
        ];

        triageWorld($this->script);

        $result = app(TriagePipeline::class)->run($this->ticket);

        expect($result->findings)->not->toHaveKey('invoices')
            ->and($result->findings)->toHaveKey('invoice_not_found')
            ->and($result->urgency->value)->toBe('normal')                                     // a stranger's 77 days did not make it urgent
            ->and(json_encode(TriageRun::sole()->toArray()))->not->toContain('INV-5555');
    });
});

describe('the other paths', function () {
    it('sends a how-to ticket through without the agent', function () {
        triageWorld([
            'votes' => ['logo' => ['howto', 'howto', 'howto']],
            'extract' => ['logo' => ['category' => 'howto']],
            'draft' => 'You can add a logo from Settings.',
        ]);

        $result = app(TriagePipeline::class)->run(triageTicket(['body' => 'How do I add my logo to invoices?', 'subject' => 'Logo'], $this->customer));

        expect($result->investigated())->toBeFalse()
            ->and($result->assignee)->toBe('docs-team')
            ->and(AgentRun::count())->toBe(0)
            ->and(chatFeatures())->not->toContain('triage.investigate');
    });

    it('gives a split vote to a person, unlabeled, with no extraction and no draft', function () {
        triageWorld(['votes' => ['broken' => ['bug', 'billing', 'account']]]);

        $ticket = triageTicket(['body' => 'Everything is broken and I was charged', 'subject' => 'Help'], $this->customer);

        $result = app(TriagePipeline::class)->run($ticket);

        $run = $result->run;

        expect($result->human)->toBeTrue()
            ->and($run->status)->toBe('human')
            ->and($run->reason)->toStartWith('ambiguous category: bug/billing/account')
            ->and($run->category)->toBe('bug')
            ->and($run->draft)->toBeNull()
            ->and($ticket->fresh()->category)->toBeNull()
            ->and($ticket->fresh()->triaged_at)->not->toBeNull()
            ->and(chatFeatures())->toBe(['ticket.classify', 'ticket.classify', 'ticket.classify']);
    });

    it('hands over what is not a support request', function () {
        triageWorld(['votes' => ['accountant' => ['other', 'other', 'other']]]);

        $result = app(TriagePipeline::class)->run(triageTicket(['body' => 'Can you recommend an accountant?'], $this->customer));

        expect($result->human)->toBeTrue()
            ->and($result->reason)->toBe('not a Ledgerly support request');
    });

    it('hands over a ticket whose customer has nobody to act as', function () {
        triageWorld();

        $orphan = Customer::factory()->create();

        $ticket = Ticket::create(['customer_id' => $orphan->id, 'subject' => 'x', 'body' => 'help', 'source' => 'form']);

        $result = app(TriagePipeline::class)->run($ticket);

        expect($result->human)->toBeTrue()
            ->and($result->reason)->toBe('the customer has no owner to act as');

        Http::assertNothingSent();
    });

    it('hands a ticket to a person, with the reason, when the classifier is down', function () {
        Http::fake(['*/chat/completions' => Http::response('down', 500)]);

        $result = app(TriagePipeline::class)->run(triageTicket(customer: $this->customer));

        expect($result->human)->toBeTrue()
            ->and($result->reason)->toBe('the classifier gave no answer');
    });

    it('records a failure on the triage run and raises it', function () {
        modelDownAfterVoting();

        $ticket = triageTicket(customer: $this->customer);

        expect(fn () => app(TriagePipeline::class)->run($ticket))->toThrow(Exception::class);

        expect(TriageRun::sole()->status)->toBe('failed')
            ->and(TriageRun::sole()->reason)->not->toBeEmpty();
    });

    it('survives a model that cannot write the draft: the findings are kept, the draft is missing', function () {
        $answered = 0;

        Http::fake([
            '*/embeddings' => fn ($request) => embeddingsResponse($request),
            '*/chat/completions' => function ($request) use (&$answered) {
                $system = (string) $request['messages'][0]['content'];

                if (str_starts_with($system, 'You draft')) {
                    return Http::response('down', 500);
                }

                return str_starts_with($system, 'You classify')
                    ? Http::response(chatBody(ticketJson('howto')))
                    : Http::response(chatBody(ticketJson('howto')));
            },
        ]);

        $result = app(TriagePipeline::class)->run(triageTicket(['body' => 'How do I add a logo?', 'subject' => 'Logo'], $this->customer));

        expect($result->run->status)->toBe('automated')
            ->and($result->draft)->toBeNull()
            ->and($result->run->draft_status)->toBeNull()
            ->and($result->run->reason)->toBe('no draft: the model did not answer');
    });
});

describe('the TicketReceived listener', function () {
    it('triages a ticket when the event fires', function () {
        triageWorld(['votes' => ['logo' => ['howto', 'howto', 'howto']], 'extract' => ['logo' => ['category' => 'howto']]]);

        $ticket = triageTicket(['body' => 'How do I add my logo to invoices?', 'subject' => 'Logo'], $this->customer);

        TicketReceived::dispatch($ticket);

        expect(TriageRun::sole()->status)->toBe('automated')
            ->and($ticket->fresh()->category)->toBe('howto');
    });

    it('is idempotent on the event id: a retried webhook is a no-op', function () {
        triageWorld(['votes' => ['logo' => ['howto', 'howto', 'howto']], 'extract' => ['logo' => ['category' => 'howto']]]);

        $ticket = triageTicket(['body' => 'How do I add my logo to invoices?', 'subject' => 'Logo'], $this->customer);

        $event = new TicketReceived($ticket, id: 'msg-123');

        event($event);

        $calls = ModelCall::count();

        event($event);
        event(new TicketReceived($ticket, id: 'msg-123'));

        expect(TriageRun::count())->toBe(1)
            ->and(ModelCall::count())->toBe($calls);
    });

    it('does not triage a ticket twice for two different events', function () {
        triageWorld(['votes' => ['logo' => ['howto', 'howto', 'howto']], 'extract' => ['logo' => ['category' => 'howto']]]);

        $ticket = triageTicket(['body' => 'How do I add my logo to invoices?', 'subject' => 'Logo'], $this->customer);

        TicketReceived::dispatch($ticket);
        TicketReceived::dispatch($ticket);

        expect(TriageRun::count())->toBe(1);
    });

    it('lets a failed run be started again by a new event', function () {
        modelDownAfterVoting();

        $ticket = triageTicket(['body' => 'How do I add my logo to invoices?', 'subject' => 'Logo'], $this->customer);

        $event = new TicketReceived($ticket, id: 'msg-9');

        expect(fn () => event($event))->toThrow(Exception::class);
        expect(TriageRun::sole()->status)->toBe('failed');

        Http::swap(new Factory);

        $this->travel(2)->minutes();                                            // Chapter 25: the breaker the failed run opened lets a call through again after a minute

        triageWorld(['votes' => ['logo' => ['howto', 'howto', 'howto']], 'extract' => ['logo' => ['category' => 'howto']]]);

        event($event);

        expect(TriageRun::count())->toBe(1)
            ->and(TriageRun::sole()->status)->toBe('automated');
    });
});
