<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Conversations\ToolResultCompactor;
use App\Ai\Prompts\SummarizePrompt;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->store = app(ConversationStore::class);
    $this->user = User::factory()->create();
    $this->conversation = conversationFor($this->user);
});

function padded(string $text, int $tokens = 40): string
{
    return $text.' '.str_repeat('x', (int) floor($tokens * 3.6));
}

function growConversation($conversation, int $exchanges): void
{
    foreach (range(1, $exchanges) as $n) {
        addExchange($conversation, padded("question {$n} about INV-447{$n}"), padded("answer {$n}"));
    }
}

function invoiceLookup(string $number = 'INV-4471'): array
{
    return ['call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null], json_encode(['ok' => true, 'found' => true, 'invoice' => ['number' => $number, 'status' => 'overdue', 'issued_at' => '2026-03-03', 'due_date' => '2026-04-02', 'days_overdue' => 18, 'total' => 1284.5, 'late_fee' => 21.6]])];
}

describe('SummarizeConversation', function () {
    it('summarizes the exchanges between the old summary and the cutoff, leaves the last four alone and the first pinned', function () {
        growConversation($this->conversation, 8);

        Http::fake(['*/chat/completions' => fn () => chatReply('Problem: late fee on INV-4471. Open: waiver.')]);

        SummarizeConversation::dispatchSync($this->conversation);

        $c = $this->conversation->fresh();

        expect($c->summary)->toBe('Problem: late fee on INV-4471. Open: waiver.')
            ->and($c->summary_through_seq)->toBe(8);                          // exchanges 2 to 4; 5 to 8 stay verbatim; 1 is pinned

        $body = sentChatBodies()[0];

        expect($body['temperature'])->toBe(0)
            ->and($body['max_tokens'])->toBe(400)
            ->and($body['messages'][0]['content'])->toBe(SummarizePrompt::system())
            ->and($body['messages'][1]['content'])->toContain('Existing summary:'.PHP_EOL.'(none yet)')
            ->and($body['messages'][1]['content'])->toContain('question 2 about INV-4472')
            ->and($body['messages'][1]['content'])->toContain('question 4 about INV-4474')
            ->and($body['messages'][1]['content'])->not->toContain('question 5 about')
            ->and($body['messages'][1]['content'])->not->toContain('question 1 about');
    });

    it('logs the call as conversation.summarize against the conversation', function () {
        growConversation($this->conversation, 8);

        Http::fake(['*/chat/completions' => fn () => chatReply('Problem: x.')]);

        SummarizeConversation::dispatchSync($this->conversation);

        $call = ModelCall::sole();

        expect($call->feature)->toBe('conversation.summarize')
            ->and($call->conversation_id)->toBe($this->conversation->id)
            ->and($call->prompt_version)->toBe(SummarizePrompt::VERSION);
    });

    it('folds the next batch into the existing summary', function () {
        growConversation($this->conversation, 8);

        $this->conversation->update(['summary' => 'Problem: late fee on INV-4471.', 'summary_through_seq' => 8]);

        growConversation($this->conversation, 4);

        Http::fake(['*/chat/completions' => fn () => chatReply('Problem: late fee on INV-4471. Tried: ticket.')]);

        SummarizeConversation::dispatchSync($this->conversation);

        $c = $this->conversation->fresh();

        expect(sentChatBodies()[0]['messages'][1]['content'])->toContain("Existing summary:\nProblem: late fee on INV-4471.")
            ->and($c->summary_through_seq)->toBe(16)
            ->and($c->summary)->toContain('Tried: ticket');
    });

    it('keeps the old summary when the model fails', function () {
        growConversation($this->conversation, 8);

        $this->conversation->update(['summary' => 'Old summary.', 'summary_through_seq' => 4]);

        Http::fake(['*/chat/completions' => Http::response('boom', 500)]);

        SummarizeConversation::dispatchSync($this->conversation);

        expect($this->conversation->fresh()->summary)->toBe('Old summary.')
            ->and($this->conversation->fresh()->summary_through_seq)->toBe(4);
    });

    it('keeps the old summary when the model returns nothing', function () {
        growConversation($this->conversation, 8);

        Http::fake(['*/chat/completions' => fn () => chatReply('   ')]);

        SummarizeConversation::dispatchSync($this->conversation);

        expect($this->conversation->fresh()->summary)->toBeNull();
    });

    it('does not call the model when there is nothing old enough to summarize', function () {
        growConversation($this->conversation, 4);

        Http::fake();

        SummarizeConversation::dispatchSync($this->conversation);

        Http::assertNothingSent();
    });

    it('writes the transcript so identifiers reach the model verbatim, tool calls included', function () {
        addExchange($this->conversation, 'first', 'first answer');
        addExchange($this->conversation, 'Why is INV-4471 late?', 'It is 18 days overdue.', invoiceLookup());
        growConversation($this->conversation, 4);

        $exchanges = $this->store->exchangesBetween($this->conversation, 0, 8);

        $user = SummarizePrompt::messages(existing: null, exchanges: $exchanges)[1]['content'];

        expect($user)->toContain('Customer: Why is INV-4471 late?')
            ->and($user)->toContain('Assistant called lookup_invoice({"invoice_number":"4471","month":null})')
            ->and($user)->toContain('Tool result (lookup_invoice): {"ok":true,"found":true')
            ->and($user)->toContain('Assistant: It is 18 days overdue.');
    });
});

describe('compaction', function () {
    beforeEach(function () {
        $this->compactor = app(ToolResultCompactor::class);
    });

    it('turns an old read result into a one-line stub two exchanges behind the live edge', function () {
        addExchange($this->conversation, 'Why is INV-4471 late?', 'It is 18 days overdue.', invoiceLookup());
        addExchange($this->conversation, 'second', 'second answer');
        addExchange($this->conversation, 'third', 'third answer');

        $tool = Message::where('role', 'tool')->first();
        $before = $tool->tokens;

        expect($this->compactor->compact($this->conversation))->toBe(1);

        $tool = $tool->fresh();

        expect($tool->content)->toBe('{"compacted":true,"tool":"lookup_invoice","summary":"returned invoice INV-4471: overdue, total 1284.5, 18 days overdue, late fee 21.6"}')
            ->and($tool->tokens)->toBeLessThan($before)
            ->and($tool->isCompacted())->toBeTrue()
            ->and($tool->tool_call_id)->toBe('call_1');
    });

    it('leaves the last two exchanges alone', function () {
        addExchange($this->conversation, 'q1', 'a1');
        addExchange($this->conversation, 'Why is INV-4471 late?', 'overdue', invoiceLookup());
        addExchange($this->conversation, 'q3', 'a3');

        expect($this->compactor->compact($this->conversation))->toBe(0)
            ->and(Message::where('role', 'tool')->first()->isCompacted())->toBeFalse();
    });

    it('can be told how far behind to compact (Exercise 3)', function () {
        addExchange($this->conversation, 'Why is INV-4471 late?', 'overdue', invoiceLookup());

        expect($this->compactor->compact($this->conversation, behind: 0))->toBe(1);
    });

    it('is idempotent', function () {
        addExchange($this->conversation, 'Why is INV-4471 late?', 'overdue', invoiceLookup());
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        $this->compactor->compact($this->conversation);

        expect($this->compactor->compact($this->conversation))->toBe(0)
            ->and($this->compactor->hasWork($this->conversation))->toBeFalse();
    });

    it('keeps write results: they are the record', function () {
        addExchange($this->conversation, 'Please log this', 'Opened T-10431.', ['call_2', 'open_ticket', ['summary' => 'x', 'invoice_number' => null], '{"ok":true,"ticket_number":"T-10431","created":true}']);
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        expect($this->compactor->compact($this->conversation))->toBe(0);
    });

    it('keeps denials and errors verbatim: the model should remember it could not', function () {
        addExchange($this->conversation, 'Look it up', 'I could not.', ['call_3', 'lookup_invoice', ['invoice_number' => 'x'], '{"ok":false,"error":"invalid arguments","details":{"invoice_number":["The invoice number field format is invalid."]}}']);
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        expect($this->compactor->compact($this->conversation))->toBe(0);
    });

    it('keeps the result of a tool it does not know', function () {
        addExchange($this->conversation, 'Do the thing', 'Done.', ['call_4', 'mystery_tool', [], padded('result', 80)]);
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        expect($this->compactor->compact($this->conversation))->toBe(0);
    });

    it('summarizes a list_invoices table in any format as the book\'s stub', function (string $table) {
        addExchange($this->conversation, 'How are my invoices?', 'Fine.', ['call_5', 'list_invoices', ['status' => null, 'months' => null], $table]);
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        $this->compactor->compact($this->conversation);

        $stub = json_decode(Message::where('role', 'tool')->first()->content, true);

        expect($stub['summary'])->toBe('3 invoices; 2 overdue: INV-4471 (18d), INV-4438 (41d)');
    })->with([
        'toon' => [implode("\n", ['Amounts in USD. Today is 2026-04-20.', 'Data below is in TOON format: ...', 'invoices[3]{number,status,due,total,late_fee,days_overdue}:', '  INV-4471,overdue,2026-04-02,1284.5,21.6,18', '  INV-4438,overdue,2026-03-10,820,35.2,41', '  INV-4461,paid,2026-03-26,2556.12,0,0', str_repeat('padding ', 40)])],
        'tsv' => [implode("\n", ['Amounts in USD.', "number\tstatus\tdue\ttotal\tlate_fee\tdays_overdue", "INV-4471\toverdue\t2026-04-02\t1284.5\t21.6\t18", "INV-4438\toverdue\t2026-03-10\t820\t35.2\t41", "INV-4461\tpaid\t2026-03-26\t2556.12\t0\t0", str_repeat('padding ', 40)])],
        'markdown' => [implode("\n", ['| number | status | due | total | late_fee | days_overdue |', '|---|---|---|---|---|---|', '| INV-4471 | overdue | 2026-04-02 | 1284.5 | 21.6 | 18 |', '| INV-4438 | overdue | 2026-03-10 | 820 | 35.2 | 41 |', '| INV-4461 | paid | 2026-03-26 | 2556.12 | 0 | 0 |', str_repeat('padding ', 40)])],
        'json' => ['{"invoices":[{"number":"INV-4471","status":"overdue","due":"2026-04-02","total":1284.5,"late_fee":21.6,"days_overdue":18},{"number":"INV-4438","status":"overdue","due":"2026-03-10","total":820,"late_fee":35.2,"days_overdue":41},{"number":"INV-4461","status":"paid","due":"2026-03-26","total":2556.12,"late_fee":0,"days_overdue":0}]}'.str_repeat(' ', 300)],
    ]);

    it('has history() emit the stub where one exists', function () {
        addExchange($this->conversation, 'Why is INV-4471 late?', 'It is 18 days overdue.', invoiceLookup());
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        $this->compactor->compact($this->conversation);

        $tool = collect($this->store->history($this->conversation, 4000))->firstWhere('role', 'tool');

        expect($tool['content'])->toStartWith('{"compacted":true,"tool":"lookup_invoice"')
            ->and($tool['tool_call_id'])->toBe('call_1');
    });

    it('runs inside the summarize job even when there is nothing to summarize', function () {
        addExchange($this->conversation, 'Why is INV-4471 late?', 'overdue', invoiceLookup());
        addExchange($this->conversation, 'q2', 'a2');
        addExchange($this->conversation, 'q3', 'a3');

        Http::fake();

        SummarizeConversation::dispatchSync($this->conversation);

        Http::assertNothingSent();

        expect(Message::where('role', 'tool')->first()->isCompacted())->toBeTrue();
    });
});
