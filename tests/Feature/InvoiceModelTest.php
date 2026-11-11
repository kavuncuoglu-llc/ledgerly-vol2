<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Ledgerly;

it('treats the configured date as today', function () {
    expect(Ledgerly::today()->toDateString())->toBe('2026-04-20');

    config(['ledgerly.as_of' => null]);

    expect(Ledgerly::today()->toDateString())->toBe(now()->toDateString());
});

it('counts days overdue from the working date', function () {
    $overdue = Invoice::factory()->overdue(18)->create();
    $sent = Invoice::factory()->create();
    $paid = Invoice::factory()->paid()->create();

    expect($overdue->daysOverdue())->toBe(18)
        ->and($sent->daysOverdue())->toBe(0)
        ->and($paid->daysOverdue())->toBe(0);
});

it('reports the late fee in currency units', function () {
    $invoice = Invoice::factory()->overdue()->withLateFee(2160)->create();

    expect($invoice->lateFee())->toBe(21.6)
        ->and($invoice->late_fee_cents)->toBe(2160);
});

it('works out the balance from the total, the late fee and the payments', function () {
    $invoice = Invoice::factory()->overdue()->withLateFee(2160)->create(['total' => 1284.50]);

    expect($invoice->balanceDue())->toBe(1306.10)
        ->and($invoice->isPartiallyPaid())->toBeFalse();

    Payment::factory()->for($invoice)->create(['amount' => 300]);

    expect($invoice->fresh()->balanceDue())->toBe(1006.10)
        ->and($invoice->fresh()->isPartiallyPaid())->toBeTrue();
});

it('casts status to the enum and keeps the sixteen columns the book prints', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and(array_keys($invoice->fresh()->toArray()))->toBe([
            'id', 'customer_id', 'number', 'status', 'currency', 'subtotal', 'tax', 'total',
            'issued_at', 'due_at', 'paid_at', 'late_fee_cents', 'notes', 'created_at', 'updated_at', 'deleted_at',
        ]);
});

it('finds an invoice by number however the customer writes it', function () {
    $invoice = Invoice::factory()->create(['number' => 'INV-4471']);

    foreach (['INV-4471', 'inv-4471', '4471', '#4471', ' 4471 ', 'INV4471'] as $written) {
        expect(Invoice::whereNumber($written)->first()?->id)->toBe($invoice->id);
    }

    expect(Invoice::whereNumber('4472')->exists())->toBeFalse();
});

it('soft deletes invoices', function () {
    $invoice = Invoice::factory()->create();
    $invoice->delete();

    expect(Invoice::count())->toBe(0)
        ->and(Invoice::withTrashed()->count())->toBe(1);
});
