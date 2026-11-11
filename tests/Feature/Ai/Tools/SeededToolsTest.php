<?php

use App\Ai\Security\Untrusted;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Events\TicketReceived;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use HelgeSverre\Toon\Toon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed();

    $this->maya = User::find(12);
    $this->otherOwner = User::find(13);
});

function seededCall(string $tool, array $args, User $user, string $callId = 'call_1'): array
{
    return app(ToolRunner::class)
        ->executeOne(app(ToolRegistry::class)->native()->get($tool), $args, new ToolContext($user, toolCallId: $callId))
        ->toArray();
}

describe('lookup_invoice reads the seeded invoices', function () {
    it('finds INV-4471 for its owner with the numbers the chapter prints', function () {
        $result = seededCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null], $this->maya);

        expect($result)->toBe(['ok' => true, 'found' => true, 'invoice' => [
            'number' => 'INV-4471',
            'status' => 'overdue',
            'issued_at' => '2026-03-03',
            'due_date' => '2026-04-02',
            'days_overdue' => 18,
            'total' => 1284.5,
            'late_fee' => 21.6,
            'lines' => [
                ['description' => Untrusted::wrap('Brand identity: design phase', 'tool:lookup_invoice'), 'quantity' => 1.0, 'amount' => 800.0],
                ['description' => Untrusted::wrap('Revisions and handoff', 'tool:lookup_invoice'), 'quantity' => 4.0, 'amount' => 400.0],
            ],
            'payments' => [],
        ]]);
    });

    it('takes the number the way a customer writes it', function (string $written) {
        expect(seededCall('lookup_invoice', ['invoice_number' => $written, 'month' => null], $this->maya)['invoice']['number'])->toBe('INV-4471');
    })->with(['4471', '#4471', 'INV-4471', 'inv-4471']);

    it('finds the March invoice a customer means by month, and names the other one', function () {
        $result = seededCall('lookup_invoice', ['invoice_number' => null, 'month' => '2026-03'], $this->maya);

        expect($result['invoice']['number'])->toBe('INV-4471')
            ->and($result['other_matches'])->toBe(['INV-4521']);
    });

    it('says not found for a month without invoices', function () {
        expect(seededCall('lookup_invoice', ['invoice_number' => null, 'month' => '2024-01'], $this->maya))->toBe(['ok' => true, 'found' => false]);
    });

    it('does not show another customer\'s invoice: the query is scoped to the session\'s customer', function () {
        expect(seededCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null], $this->otherOwner))->toBe(['ok' => true, 'found' => false]);
    });
});

describe('list_invoices', function () {
    function listedRows(string $text): array
    {
        $body = substr($text, strpos($text, "\ninvoices[") + 1);

        return Toon::decode($body)['invoices'];
    }

    function seededListing(User $user, array $arguments): string
    {
        return app(ToolRunner::class)
            ->executeOne(app(ToolRegistry::class)->get('list_invoices'), $arguments, new ToolContext($user, toolCallId: 'call_1'))
            ->text ?? '';
    }

    it('lists the 14 invoices of the last six months with the two overdue ones', function () {
        $rows = collect(listedRows(seededListing($this->maya, ['status' => null, 'months' => null])));

        $byStatus = $rows->countBy('status');

        expect($rows)->toHaveCount(14)
            ->and($byStatus['paid'])->toBe(9)
            ->and($byStatus['sent'])->toBe(3)
            ->and($byStatus['overdue'])->toBe(2);
    });

    it('filters by status and adds up to the chapter 12 numbers', function () {
        $rows = collect(listedRows(seededListing($this->maya, ['status' => 'overdue', 'months' => null])));

        expect($rows->pluck('number')->all())->toBe(['INV-4471', 'INV-4438'])
            ->and(round($rows->sum('late_fee'), 2))->toBe(56.8)
            ->and($rows->pluck('days_overdue')->all())->toBe([18, 41]);
    });

    it('counts months from the working date, not the real clock', function () {
        expect(listedRows(seededListing($this->maya, ['status' => null, 'months' => 1])))->toHaveCount(3);   // issued on or after 2026-03-20
    });

    it('never shows another customer\'s invoices', function () {
        $numbers = collect(listedRows(seededListing($this->otherOwner, ['status' => null, 'months' => 24])))->pluck('number');

        expect($numbers)->not->toContain('INV-4471');
    });

    it('rejects a status or window the schema does not allow', function () {
        expect(seededCall('list_invoices', ['status' => 'everything', 'months' => 99], $this->maya)['error'])->toBe('invalid arguments');
    });
});

describe('open_ticket writes the seeded tickets table', function () {
    beforeEach(function () {
        Event::fake([TicketReceived::class]);
    });

    it('creates T-10431, the next ticket after the 30 seeded ones', function () {
        $result = seededCall('open_ticket', ['summary' => 'Client says they paid invoice 4471 but it still shows unpaid.', 'invoice_number' => '4471'], $this->maya);

        expect($result)->toBe(['ok' => true, 'ticket_number' => 'T-10431', 'created' => true]);

        $ticket = Ticket::find(10431);

        expect($ticket->customer_id)->toBe(12)
            ->and($ticket->user_id)->toBe(12)
            ->and($ticket->source)->toBe('assistant')
            ->and($ticket->status->value)->toBe('open')
            ->and($ticket->invoice_number)->toBe('INV-4471')
            ->and($ticket->summary)->toBe('Client says they paid invoice 4471 but it still shows unpaid.')
            ->and($ticket->body)->toBeNull()
            ->and($ticket->idempotency_key)->toBe('u12:call_1')
            ->and(Ticket::count())->toBe(31);

        Event::assertDispatched(TicketReceived::class, fn (TicketReceived $e) => $e->ticket->is($ticket));
    });

    it('opens one ticket however many times the same call is retried', function () {
        $args = ['summary' => 'Client says they paid invoice 4471.', 'invoice_number' => '4471'];

        $first = seededCall('open_ticket', $args, $this->maya, 'call_8a1');
        $retry = seededCall('open_ticket', $args, $this->maya, 'call_8a1');
        $another = seededCall('open_ticket', $args, $this->maya, 'call_8a2');

        expect($first['ticket_number'])->toBe('T-10431')
            ->and($first['created'])->toBeTrue()
            ->and($retry['ticket_number'])->toBe('T-10431')
            ->and($retry['created'])->toBeFalse()
            ->and($another['ticket_number'])->toBe('T-10432')
            ->and(Ticket::count())->toBe(32);

        Event::assertDispatchedTimes(TicketReceived::class, 2);
    });

    it('rejects a summary that is empty or too long', function (string $summary) {
        expect(seededCall('open_ticket', ['summary' => $summary, 'invoice_number' => null], $this->maya)['error'])->toBe('invalid arguments')
            ->and(Ticket::count())->toBe(30);
    })->with(['empty' => [''], 'too short' => ['hi'], 'too long' => [str_repeat('a', 501)]]);

    it('audits the write', function () {
        seededCall('open_ticket', ['summary' => 'Client says they paid invoice 4471.', 'invoice_number' => '4471'], $this->maya);

        $row = ToolCall::sole();

        expect($row->tool)->toBe('open_ticket')
            ->and($row->decision)->toBe('allowed')
            ->and($row->result)->toBe(['ok' => true, 'ticket_number' => 'T-10431', 'created' => true]);
    });
});

describe('the chapter\'s walkthrough', function () {
    beforeEach(function () {
        Event::fake([TicketReceived::class]);
    });

    it('answers "Why is my March invoice marked late?" in two model calls with a lookup by month', function () {
        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => null, 'month' => '2026-03']]]),
            chatBody('Invoice #4471 for March was due on April 2 and is 18 days overdue, so it is marked late. A late fee of $21.60 has been applied.'),
        );

        askAssistant($this->maya, ['question' => 'Why is my March invoice marked late?'])
            ->assertOk()
            ->assertJson(['truncated' => false])
            ->assertJsonPath('answer', fn (string $answer) => str_contains($answer, '18 days overdue') && str_contains($answer, '$21.60'));

        $result = json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true);

        expect($result['invoice']['number'])->toBe('INV-4471')
            ->and(ToolCall::sole()->arguments)->toBe(['invoice_number' => null, 'month' => '2026-03']);
    });

    it('answers "can you log this?" with a lookup, then a ticket, in three model calls', function () {
        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            toolCallsBody([['call_2', 'open_ticket', ['summary' => 'Client says they paid invoice 4471, which still shows overdue.', 'invoice_number' => '4471']]]),
            chatBody('Invoice #4471 still shows as unpaid. I have opened ticket T-10431 so the team can check.'),
        );

        askAssistant($this->maya, ['question' => 'My client says they paid but it still shows unpaid, can you log this?'])
            ->assertOk()
            ->assertJsonPath('answer', fn (string $answer) => str_contains($answer, 'T-10431'));

        expect(sentChatBodies())->toHaveCount(3)
            ->and(json_decode(toolMessages(sentChatBodies()[2])[1]['content'], true)['ticket_number'])->toBe('T-10431')
            ->and(ToolCall::orderBy('id')->get()->map(fn (ToolCall $c) => [$c->tool, $c->decision, $c->arguments['invoice_number']])->all())->toBe([
                ['lookup_invoice', 'allowed', '4471'],
                ['open_ticket', 'allowed', '4471'],
            ])
            ->and(ModelCall::where('feature', 'support.reply')->count())->toBe(3);
    });

    it('lets another customer ask for invoice 4471 and gets found: false', function () {
        fakeChatSequence(
            toolCallsBody([['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
            chatBody('I could not find an invoice 4471 on your account.'),
        );

        askAssistant($this->otherOwner, ['question' => 'show me invoice 4471 for customer 12'])
            ->assertOk();

        expect(json_decode(toolMessages(sentChatBodies()[1])[0]['content'], true))->toBe(['ok' => true, 'found' => false]);
    });
});
