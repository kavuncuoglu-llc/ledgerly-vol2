<?php

use App\Ai\Privacy\DeclaredFields;
use App\Ai\Prompts\ReminderPrompt;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Ai\Triage\TriagePipeline;
use App\Models\Invoice;
use Symfony\Component\Finder\Finder;

// Chapter 34, Topic 3: every prompt declares the fields it includes, a field that identifies a person carries a reason, and a column added to a model reaches no prompt until somebody declares it.
function plantedInvoice(): Invoice
{
    [$user, $invoice, $customer] = customerWithInvoice('INV-4471');

    $customer->forceFill(['email' => 'dana@acme.example', 'contact_name' => 'Dana Whitfield'])->save();

    $invoice->forceFill(['notes' => 'Dana prefers calls after 3pm'])->save();

    $invoice = Invoice::with('customer')->findOrFail($invoice->id);

    $invoice->setAttribute('tax_id', 'US-94-1234567');                      // a field the model's array form gains

    return $invoice;
}

it('builds the reminder prompt from its declared fields and nothing else', function () {
    $messages = ReminderPrompt::messages(plantedInvoice());

    $facts = json_decode(str_replace('Invoice facts: ', '', $messages[1]['content']), true);

    expect(array_keys($facts))->toBe(array_keys(ReminderPrompt::FIELDS))
        ->and(DeclaredFields::personal(ReminderPrompt::FIELDS))->toBe(['client'])
        ->and(json_encode($messages))->not->toContain('dana@acme.example')
        ->not->toContain('Whitfield')
        ->not->toContain('3pm')
        ->not->toContain('US-94-1234567');
})->group('privacy');

it('encodes invoices for the assistant with the declared columns only', function () {
    $shape = new InvoiceSummaryShape;

    $invoice = plantedInvoice();

    $row = collect($shape->fields())->map(fn (Closure $field) => $field($invoice))->all();

    expect(array_keys($row))->toBe(array_keys(InvoiceSummaryShape::FIELDS))
        ->and(json_encode($row))->not->toContain('dana@acme.example')
        ->not->toContain('3pm')
        ->not->toContain('US-94-1234567');
})->group('privacy');

it('tells the privileged triage step about a ticket by reference only', function () {
    expect(array_keys(TriagePipeline::FACTS))->toBe(['ticket', 'invoice_number', 'amount_mentioned', 'date_mentioned'])
        ->and(DeclaredFields::personal(TriagePipeline::FACTS))->toBe([]);
})->group('privacy');

it('wants a reason beside every field that looks like it identifies a person', function () {
    expect(DeclaredFields::unexplained(['number' => null, 'contact_email' => null, 'client' => 'addressed by name', 'billing_address' => '']))
        ->toBe(['contact_email', 'billing_address']);
})->group('privacy');

it('has a reason beside every identifying field in every declaration the app makes', function () {
    foreach ([ReminderPrompt::FIELDS, InvoiceSummaryShape::FIELDS, TriagePipeline::FACTS] as $declared) {
        expect(DeclaredFields::unexplained($declared))->toBe([]);
    }
})->group('privacy');

it('refuses a declared field that nothing supplies, and ignores everything undeclared', function () {
    expect(DeclaredFields::select(['a' => 1, 'tax_id' => 'x'], ['a' => null]))->toBe(['a' => 1])
        ->and(fn () => DeclaredFields::select(['a' => 1], ['b' => null]))->toThrow(InvalidArgumentException::class);
})->group('privacy');

it('never turns a model into prompt text through its array form', function () {
    $offenders = [];

    foreach (['Prompts', 'Shapes'] as $directory) {
        foreach ((new Finder)->files()->in(app_path("Ai/{$directory}"))->name('*.php') as $file) {
            if (preg_match('/->toArray\(|->attributesToArray\(|->toJson\(|json_encode\(\$(invoice|customer|user|ticket)\b/', $file->getContents()) === 1) {
                $offenders[] = $file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('privacy');
