<?php

use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Payment;
use App\Models\Ticket;

beforeEach(function () {
    helpLibrary([]);
});

it('shows the dashboard', function () {
    Customer::factory()->count(2)->create();
    Invoice::factory()->overdue(40)->withLateFee(3520)->create();

    $this->get('/')
        ->assertOk()
        ->assertSeeText('Ledgerly')
        ->assertSeeText('Longest overdue')
        ->assertSeeText('Apr 20, 2026');
});

it('lists and searches customers', function () {
    Customer::factory()->create(['name' => 'Acme Studios']);
    Customer::factory()->create(['name' => 'Birchwood Print Co.']);

    $this->get('/customers')
        ->assertOk()
        ->assertSeeText('Acme Studios')
        ->assertSeeText('Birchwood Print Co.');

    $this->get('/customers?q=birch')
        ->assertOk()
        ->assertSeeText('Birchwood Print Co.')
        ->assertDontSeeText('Acme Studios');
});

it('shows a customer with invoices, tickets and people', function () {
    $customer = Customer::factory()->create(['name' => 'Acme Studios']);
    Invoice::factory()->for($customer)->create(['number' => 'INV-4471']);
    Ticket::factory()->for($customer)->create(['subject' => 'Where is my payout?']);

    $this->get("/customers/{$customer->id}")
        ->assertOk()
        ->assertSeeText('Acme Studios')
        ->assertSeeText('INV-4471')
        ->assertSeeText('Where is my payout?');
});

it('lists invoices and filters by status and late fee', function () {
    Invoice::factory()->overdue(40)->withLateFee(3520)->create(['number' => 'INV-5001']);
    Invoice::factory()->create(['number' => 'INV-5002']);
    Invoice::factory()->paid()->create(['number' => 'INV-5003']);

    $this->get('/invoices')
        ->assertOk()
        ->assertSeeText(['INV-5001', 'INV-5002', 'INV-5003']);

    $this->get('/invoices?status=overdue')
        ->assertSeeText('INV-5001')
        ->assertDontSeeText('INV-5002');

    $this->get('/invoices?late_fees=1')
        ->assertSeeText('INV-5001')
        ->assertDontSeeText('INV-5003');

    $this->get('/invoices?q=5003')
        ->assertSeeText('INV-5003')
        ->assertDontSeeText('INV-5001');
});

it('ignores an unknown status filter', function () {
    Invoice::factory()->create(['number' => 'INV-5002']);

    $this->get('/invoices?status=banana')->assertOk()->assertSeeText('INV-5002');
});

it('shows an invoice with the details a support person needs', function () {
    $invoice = Invoice::factory()->overdue(18)->withLateFee(2160)->create([
        'number' => 'INV-4471',
        'subtotal' => 1200,
        'tax' => 84.50,
        'total' => 1284.50,
    ]);
    InvoiceLineItem::factory()->for($invoice)->create(['description' => 'Brand identity', 'quantity' => 1, 'unit_price' => 1200, 'amount' => 1200]);
    Payment::factory()->for($invoice)->create(['amount' => 200]);

    $this->get("/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSeeText('INV-4471')
        ->assertSeeText('Overdue')
        ->assertSeeText('Days overdue')
        ->assertSeeText('18')
        ->assertSeeText('Brand identity')
        ->assertSeeText('$1,284.50')
        ->assertSeeText('Late fee applied')
        ->assertSeeText('$21.60')
        ->assertSeeText('Partial')
        ->assertSeeText('$1,106.10');
});

it('lists the support inbox and filters by state', function () {
    Ticket::factory()->create(['subject' => 'Open one', 'status' => TicketStatus::Open]);
    Ticket::factory()->create(['subject' => 'Solved one', 'status' => TicketStatus::Solved]);

    $this->get('/tickets')
        ->assertOk()
        ->assertSeeText(['Open one', 'Solved one']);

    $this->get('/tickets?status=solved')
        ->assertSeeText('Solved one')
        ->assertDontSeeText('Open one');
});

it('shows a ticket with its linked invoice', function () {
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->for($customer)->create(['number' => 'INV-4471']);
    $ticket = Ticket::factory()->for($customer)->billing()->create(['invoice_number' => 'INV-4471']);

    $this->get("/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSeeText($ticket->number)
        ->assertSeeText('My client says they paid on March 3')
        ->assertSee(route('invoices.show', $invoice), false);
});

it('returns 404 for records that do not exist', function () {
    $this->get('/customers/999999')->assertNotFound();
    $this->get('/invoices/999999')->assertNotFound();
    $this->get('/tickets/999999')->assertNotFound();
});

it('answers the framework health check', function () {
    $this->get('/up')->assertOk();
});
