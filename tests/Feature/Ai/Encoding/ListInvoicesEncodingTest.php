<?php

use App\Ai\Encoding\DataEncoder;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Ai\Tokens\RatioCounter;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->seed();

    $this->maya = User::find(12);

    $this->acmeInvoices = fn () => Customer::find(12)->invoices()
        ->where('issued_at', '>=', '2025-10-20')
        ->latest('issued_at')
        ->get();
});

function askWithListInvoices(User $user, array $arguments = ['status' => null, 'months' => null]): array
{
    fakeChatSequence(
        toolCallsBody([['call_8a1', 'list_invoices', $arguments]]),
        chatBody('You have 14 invoices in the last six months: 9 paid, 3 sent and 2 overdue.', usage: ['prompt_tokens' => 1118, 'completion_tokens' => 41]),
    );

    askAssistant($user, ['question' => 'How are my invoices looking overall?'])->assertOk();

    return sentChatBodies();
}

it('shapes Acme\'s 14 invoices to six fields and states the currency and the date once', function () {
    $rows = ($this->acmeInvoices)();

    $text = app(DataEncoder::class)->encode($rows, new InvoiceSummaryShape, 'toon', 'invoices');

    expect($rows)->toHaveCount(14)
        ->and($text)->toStartWith("Amounts in USD. Today is 2026-04-20.\nData below is in TOON format")
        ->and($text)->toContain('invoices[14]{number,status,due,total,late_fee,days_overdue}:')
        ->and($text)->toContain('  INV-4471,overdue,2026-04-02,1284.5,21.6,18')
        ->and($text)->toContain('  INV-4438,overdue,2026-03-10,820,35.2,41');
});

it('sends the tool result to the model as an encoded table, not as toJson()', function () {
    $bodies = askWithListInvoices($this->maya);

    $toolMessage = toolMessages($bodies[1])[0];

    expect($toolMessage['tool_call_id'])->toBe('call_8a1')
        ->and($toolMessage['content'])->toContain('invoices[14]{number,status,due,total,late_fee,days_overdue}:')
        ->and($toolMessage['content'])->not->toContain('created_at')
        ->and($toolMessage['content'])->not->toContain('customer_id');
});

it('offers list_invoices to the assistant now', function () {
    $bodies = askWithListInvoices($this->maya);

    $names = collect($bodies[0]['tools'])->pluck('function.name')->all();

    expect($names)->toContain('list_invoices')->toContain('lookup_invoice');
});

it('adds up to the numbers the book\'s answer prints: 9 paid, 3 sent, 2 overdue, $2,104.50 and $56.80 in fees', function () {
    $rows = ($this->acmeInvoices)();

    $overdue = $rows->filter(fn (Invoice $i) => $i->status->value === 'overdue');

    expect($rows->countBy(fn (Invoice $i) => $i->status->value)->all())->toBe(['sent' => 3, 'overdue' => 2, 'paid' => 9])
        ->and(round($overdue->sum('total'), 2))->toBe(2104.5)
        ->and(round($overdue->sum(fn (Invoice $i) => $i->lateFee()), 2))->toBe(56.8)
        ->and($overdue->map->daysOverdue()->sort()->values()->all())->toBe([18, 41]);
});

it('switches format with one config line and the answer path does not change', function (string $format, string $marker) {
    config(['ai.encoding.default' => $format]);

    $bodies = askWithListInvoices($this->maya);

    expect(toolMessages($bodies[1])[0]['content'])->toContain($marker);
})->with([
    'toon' => ['toon', 'invoices[14]{'],
    'tsv' => ['tsv', "number\tstatus\tdue\ttotal\tlate_fee\tdays_overdue"],
    'markdown' => ['markdown', '| number | status | due | total | late_fee | days_overdue |'],
    'json' => ['json', '{"invoices":[{"number":"INV-4541"'],
]);

it('logs the call with the prompt tokens the server reported', function () {
    askWithListInvoices($this->maya);

    $replies = ModelCall::where('feature', 'support.reply')->orderBy('id')->get();

    expect($replies)->toHaveCount(2)
        ->and($replies->last()->prompt_tokens)->toBe(1118);
});

it('costs a fraction of toJson() in tokens, in the order the chapter claims', function () {
    $rows = ($this->acmeInvoices)();

    $encoder = app(DataEncoder::class);

    $counter = new RatioCounter;

    $tokens = fn (string $format) => $counter->count($encoder->encode($rows, new InvoiceSummaryShape, $format, 'invoices'));

    $toon = $tokens('toon');
    $tsv = $tokens('tsv');
    $markdown = $tokens('markdown');
    $json = $tokens('json');
    $pretty = $tokens('json-pretty');
    $everything = $counter->count($rows->toJson());

    expect($toon)->toBeLessThan($markdown)
        ->and($tsv)->toBeLessThan($markdown)
        ->and($markdown)->toBeLessThan($json)
        ->and($json)->toBeLessThan($pretty)
        ->and($toon)->toBeLessThan($json * 0.5)
        ->and($toon)->toBeLessThan($everything * 0.2);
});

it('shapes the selected columns only: no ids, no timestamps, no foreign keys', function () {
    $invoice = Invoice::whereNumber('INV-4471')->first();

    $shape = new InvoiceSummaryShape;

    expect(array_keys($shape->fields()))->toBe(['number', 'status', 'due', 'total', 'late_fee', 'days_overdue'])
        ->and(array_map(fn ($format) => $format($invoice), $shape->fields()))->toBe([
            'number' => 'INV-4471',
            'status' => 'overdue',
            'due' => '2026-04-02',
            'total' => 1284.5,
            'late_fee' => 21.6,
            'days_overdue' => 18,
        ]);
});

it('returns no header and no rows for a customer with no invoices', function () {
    $newcomer = User::factory()->owner(Customer::factory()->create())->create();

    $bodies = askWithListInvoices($newcomer);

    expect(toolMessages($bodies[1])[0]['content'])->toContain('invoices: []');
});
