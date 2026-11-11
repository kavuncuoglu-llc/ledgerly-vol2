<?php

use App\Ai\Tools\ToolRegistry;

// Chapter 37, Figure 37-4: a reordered or reworded tool list is a different prefix, and a different prefix is a cache miss. The list the support assistant offers is pinned: change it on purpose and change this test with it.
it('offers the support tools sorted by name, so the order never depends on how they were registered', function () {
    $names = collect(app(ToolRegistry::class)->definitions())->pluck('function.name')->all();

    expect($names)->toBe(['account_status', 'change_billing_email', 'check_payment_status', 'issue_credit', 'list_invoices', 'lookup_invoice', 'open_ticket', 'resend_invoices', 'retry_payment']);
});

it('renders the same definitions, byte for byte, every time', function () {
    $first = json_encode(app(ToolRegistry::class)->definitions());

    app()->forgetInstance(ToolRegistry::class);

    expect(json_encode(app(ToolRegistry::class)->definitions()))->toBe($first);
});
