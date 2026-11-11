<?php

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    helpLibrary([]);

    $this->seed();
});

function seedFingerprint(): array
{
    return collect(['customers', 'tenants', 'users', 'invoices', 'invoice_line_items', 'payments', 'tickets'])
        ->mapWithKeys(fn (string $table) => [
            $table => DB::selectOne("select md5(string_agg(t::text, '|' order by t.id)) as hash from {$table} t")->hash,
        ])
        ->all();
}

it('seeds 40 customers, 600 invoices and a small support inbox', function () {
    expect(Customer::count())->toBe(40)
        ->and(Invoice::count())->toBe(600)
        ->and(Ticket::count())->toBe(30);
});

it('seeds an owner for every customer and two support staff', function () {
    expect(User::where('role', 'owner')->count())->toBe(40)
        ->and(User::where('role', 'support')->count())->toBe(2)
        ->and(Customer::whereDoesntHave('owner')->count())->toBe(0)
        ->and(Hash::check('password', User::find(12)->password))->toBeTrue();
});

it('seeds invoices in every status, including overdue ones and ones with late fees', function () {
    foreach (InvoiceStatus::cases() as $status) {
        expect(Invoice::where('status', $status->value)->count())->toBeGreaterThan(0, "no {$status->value} invoices");
    }

    expect(Invoice::where('status', 'overdue')->count())->toBeGreaterThanOrEqual(30)
        ->and(Invoice::withLateFee()->count())->toBeGreaterThanOrEqual(30)
        ->and(Invoice::where('status', 'overdue')->withLateFee()->count())->toBeGreaterThanOrEqual(20);
});

it('keeps line items, subtotals, tax and totals consistent', function () {
    $broken = DB::select('
        select i.id from invoices i
        left join (select invoice_id, sum(amount) as lines, count(*) as line_count from invoice_line_items group by invoice_id) l on l.invoice_id = i.id
        where l.line_count is null or l.lines <> i.subtotal or i.total <> i.subtotal + i.tax
    ');

    expect($broken)->toBe([]);
});

it('settles paid invoices in full and leaves the others unpaid', function () {
    $paid = Invoice::where('status', 'paid')->with('payments')->get();

    foreach ($paid as $invoice) {
        expect($invoice->paid_at)->not->toBeNull()
            ->and($invoice->balanceDue())->toBe(0.0, $invoice->number);
    }

    expect(Invoice::where('status', '!=', 'paid')->whereNotNull('paid_at')->count())->toBe(0)
        ->and(Invoice::where('status', 'overdue')->whereNotNull('paid_at')->count())->toBe(0);
});

it('dates overdue and sent invoices consistently with the working date', function () {
    $today = '2026-04-20 00:00:00';

    expect(Invoice::where('status', 'overdue')->where('due_at', '>=', $today)->count())->toBe(0)
        ->and(Invoice::where('status', 'sent')->where('due_at', '<', $today)->count())->toBe(0)
        ->and(Invoice::where('issued_at', '>', '2026-04-20 23:59:59')->count())->toBe(0);
});

it('only charges late fees on overdue or paid-late invoices after the grace period', function () {
    $tooEarly = Invoice::where('status', 'overdue')->withLateFee()->get()
        ->filter(fn (Invoice $invoice) => $invoice->daysOverdue() <= 30 && ! in_array($invoice->number, ['INV-4471']));

    expect($tooEarly)->toBeEmpty()
        ->and(Invoice::whereIn('status', ['draft', 'sent', 'void'])->withLateFee()->count())->toBe(0);
});

it('explains why some long overdue invoices carry no late fee', function () {
    $paused = Invoice::where('status', 'overdue')->where('late_fee_cents', 0)->whereNotNull('notes')->pluck('notes');

    expect($paused)->not->toBeEmpty();

    foreach ($paused as $note) {
        expect($note)->toMatch('/Disputed|payment plan/');
    }
});

it('seeds the invoices the book keeps returning to', function () {
    $march = Invoice::whereNumber('4471')->with('customer')->first();

    expect($march->id)->toBe(4471)
        ->and($march->customer->id)->toBe(12)
        ->and($march->customer->name)->toBe('Acme Studios')
        ->and($march->status)->toBe(InvoiceStatus::Overdue)
        ->and($march->issued_at->toDateString())->toBe('2026-03-03')
        ->and($march->due_at->toDateString())->toBe('2026-04-02')
        ->and($march->daysOverdue())->toBe(18)
        ->and($march->total)->toBe('1284.50')
        ->and($march->late_fee_cents)->toBe(2160)
        ->and($march->lateFee())->toBe(21.6);

    $older = Invoice::whereNumber('INV-4438')->first();

    expect($older->daysOverdue())->toBe(41)
        ->and($older->late_fee_cents)->toBe(3520);
});

it('gives Acme Studios the fourteen invoices in six months that Chapter 12 summarises', function () {
    $recent = Invoice::where('customer_id', 12)
        ->where('issued_at', '>=', now()->setDate(2025, 10, 20)->startOfDay())
        ->pluck('status')
        ->map->value
        ->countBy()
        ->sortKeys()
        ->all();

    expect($recent)->toBe(['overdue' => 2, 'paid' => 9, 'sent' => 3]);

    $overdue = Invoice::where('customer_id', 12)->where('status', 'overdue')->get();

    expect((float) $overdue->sum('total'))->toBe(2104.5)
        ->and($overdue->sum('late_fee_cents'))->toBe(5680);
});

it('numbers invoices from 3951 and tickets from 10401 so ids look like the book', function () {
    expect(Invoice::min('id'))->toBe(3951)
        ->and(Invoice::max('id'))->toBe(4550)
        ->and(Ticket::min('id'))->toBe(10401)
        ->and(Ticket::max('id'))->toBe(10430)
        ->and(Invoice::factory()->create()->id)->toBe(4551)
        ->and(Ticket::factory()->create()->id)->toBe(10431);
});

it('seeds the tickets Chapter 12 lists', function () {
    $billing = Ticket::find(10422);

    expect($billing->number)->toBe('T-10422')
        ->and($billing->customer_id)->toBe(12)
        ->and($billing->category)->toBe('billing')
        ->and($billing->invoice_number)->toBe('INV-4471')
        ->and($billing->body)->toContain('paid on March 3')
        ->and(Ticket::find(10423)->category)->toBe('howto')
        ->and(Ticket::find(10425)->category)->toBe('bug');
});

it('only mentions invoices that exist and belong to the ticket customer', function () {
    $withInvoices = Ticket::whereNotNull('invoice_number')->get();

    expect($withInvoices)->not->toBeEmpty();

    foreach ($withInvoices as $ticket) {
        $invoice = Invoice::whereNumber($ticket->invoice_number)->first();

        expect($invoice)->not->toBeNull()
            ->and($invoice->customer_id)->toBe($ticket->customer_id);
    }
});

it('seeds the same data every time', function () {
    $first = seedFingerprint();

    DB::statement('TRUNCATE customers, tenants, users, invoices, invoice_line_items, payments, tickets RESTART IDENTITY CASCADE');

    expect(Invoice::count())->toBe(0);

    $this->seed();

    expect(seedFingerprint())->toBe($first);
});

it('keeps real people out of the data', function () {
    $emails = User::pluck('email')->merge(Customer::pluck('email'));

    foreach ($emails as $email) {
        expect($email)->toMatch('/@([a-z0-9-]+\.)?(example\.com|ledgerly\.test)$/');
    }
});
