<?php

use App\Ai\Schemas\InvoiceData;
use App\Ai\Schemas\LineItem;
use Illuminate\Support\Facades\Validator;

function validateInvoice(array $data): Illuminate\Validation\Validator
{
    $v = Validator::make($data, InvoiceData::rules());

    foreach (InvoiceData::afterRules() as $after) {
        $v->after($after);
    }

    $v->passes();

    return $v;
}

it('accepts the Northgate invoice', function () {
    expect(validateInvoice(northgateInvoice())->errors()->isEmpty())->toBeTrue();
});

it('builds a typed object with LineItem objects', function () {
    $invoice = InvoiceData::fromArray(validateInvoice(northgateInvoice())->validated());

    expect($invoice->vendor_name)->toBe('Northgate Paper Co')
        ->and($invoice->total)->toBe(1284.5)
        ->and($invoice->tax)->toBe(0.0)
        ->and($invoice->line_items)->toHaveCount(5)
        ->and($invoice->line_items[0])->toBeInstanceOf(LineItem::class)
        ->and($invoice->line_items[0]->quantity)->toBe(40.0)
        ->and($invoice->meta)->toBeNull();
});

it('catches line items that do not add up to the subtotal', function () {
    $errors = validateInvoice(northgateInvoice(['subtotal' => 1204.50, 'total' => 1204.50]))->errors();

    expect($errors->first('subtotal'))->toBe('Line items sum to 1284.50, not 1204.50.');
});

it('catches a total that is not subtotal plus tax', function () {
    $errors = validateInvoice(northgateInvoice(['total' => 1204.50]))->errors();

    expect($errors->first('total'))->toBe('Total does not equal subtotal plus tax.')
        ->and($errors->has('subtotal'))->toBeFalse();
});

it('allows a total within a cent', function () {
    expect(validateInvoice(northgateInvoice(['total' => 1284.505]))->errors()->isEmpty())->toBeTrue();
});

it('checks the arithmetic only when the fields are present', function () {
    $invoice = northgateInvoice(['subtotal' => null, 'tax' => null, 'total' => null]);

    expect(validateInvoice($invoice)->errors()->isEmpty())->toBeTrue();
});

it('rejects each kind of value error', function (string $field, mixed $value, string $rule) {
    $errors = validateInvoice(northgateInvoice([$field => $value]))->errors();

    expect($errors->has($field))->toBeTrue($rule);
})->with([
    'wrong date format' => ['invoice_date', '03/15/2026', 'date_format'],
    'wrong century' => ['invoice_date', '1926-03-15', 'after'],
    'due before invoice' => ['due_date', '2026-03-01', 'after_or_equal'],
    'not a currency' => ['currency', 'USA', 'size'],
    'unknown currency' => ['currency', 'XXX', 'in'],
    'a string for a number' => ['total', '1,284.50', 'numeric'],
    'negative tax' => ['tax', -1, 'min'],
]);

it('rejects a line item with a missing field or a non-numeric amount', function () {
    $items = northgateInvoice()['line_items'];

    unset($items[0]['description']);

    expect(validateInvoice(northgateInvoice(['line_items' => $items]))->errors()->has('line_items.0.description'))->toBeTrue();

    $items = northgateInvoice()['line_items'];
    $items[1]['amount'] = 'abc';

    expect(validateInvoice(northgateInvoice(['line_items' => $items]))->errors()->has('line_items.1.amount'))->toBeTrue();
});

it('caps the number of line items', function () {
    $items = array_fill(0, 201, ['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1]);

    expect(validateInvoice(northgateInvoice(['line_items' => $items, 'subtotal' => 201, 'total' => 201]))->errors()->has('line_items'))->toBeTrue();
});
