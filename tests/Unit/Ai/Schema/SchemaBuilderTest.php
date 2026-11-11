<?php

use App\Ai\Schema\Describe;
use App\Ai\Schema\SchemaBuilder;
use App\Ai\Schemas\Category;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Schemas\TicketData;

it('lists every InvoiceData property as required and forbids extras', function () {
    $schema = SchemaBuilder::for(InvoiceData::class);

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['vendor_name', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'line_items', 'subtotal', 'tax', 'total'])
        ->and(array_keys($schema['properties']))->toBe($schema['required']);
});

it('does not describe the extraction metadata the client attaches afterwards', function () {
    expect(SchemaBuilder::for(InvoiceData::class)['properties'])->not->toHaveKey('meta');
});

it('types nullable fields as a union with null and numbers as number', function () {
    $properties = SchemaBuilder::for(InvoiceData::class)['properties'];

    expect($properties['vendor_name']['type'])->toBe(['string', 'null'])
        ->and($properties['total']['type'])->toBe(['number', 'null'])
        ->and($properties['vendor_name']['description'])->toBe('Vendor or supplier name as printed');
});

it('builds the item schema for LineItem[] from the docblock', function () {
    $items = SchemaBuilder::for(InvoiceData::class)['properties']['line_items'];

    expect($items['type'])->toBe('array')
        ->and($items['items']['additionalProperties'])->toBeFalse()
        ->and($items['items']['required'])->toBe(['description', 'quantity', 'unit_price', 'amount'])
        ->and($items['items']['properties']['quantity']['type'])->toBe('number')
        ->and($items['items']['properties']['description']['type'])->toBe('string');
});

it('turns a backed enum into an enum of its values', function () {
    $category = SchemaBuilder::for(TicketData::class)['properties']['category'];

    expect($category['type'])->toBe('string')
        ->and($category['enum'])->toBe(array_map(fn (Category $case) => $case->value, Category::cases()))
        ->and($category['enum'])->toBe(['billing', 'howto', 'bug', 'account', 'other']);
});

it('adds null to the enum when the enum is nullable', function () {
    $class = new class(null)
    {
        public function __construct(public ?Category $category) {}
    };

    $property = SchemaBuilder::for($class::class)['properties']['category'];

    expect($property['type'])->toBe(['string', 'null'])
        ->and($property['enum'])->toBe(['billing', 'howto', 'bug', 'account', 'other', null]);
});

it('names a schema after its class in snake case', function () {
    expect(SchemaBuilder::name(InvoiceData::class))->toBe('invoice_data')
        ->and(SchemaBuilder::name(TicketData::class))->toBe('ticket_data');
});

it('refuses a property without a type', function () {
    $class = new class('x')
    {
        public function __construct(public $untyped) {}
    };

    SchemaBuilder::for($class::class);
})->throws(InvalidArgumentException::class, 'needs a single named type');

it('refuses an array whose items are unknown', function () {
    $class = new class([])
    {
        public function __construct(public array $things) {}
    };

    SchemaBuilder::for($class::class);
})->throws(InvalidArgumentException::class, 'array of unknown items');

it('reads array items from the attribute too', function () {
    $class = new class([])
    {
        public function __construct(#[Describe('Tags', items: 'string')] public array $tags) {}
    };

    expect(SchemaBuilder::for($class::class)['properties']['tags'])->toBe([
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => 'Tags',
    ]);
});

it('has a description on every InvoiceData and TicketData property', function () {
    foreach ([InvoiceData::class, TicketData::class] as $class) {
        foreach (SchemaBuilder::for($class)['properties'] as $name => $property) {
            expect($property['description'] ?? null)->toBeString("{$class}::{$name}");
        }
    }
});

it('stays inside what strict mode accepts: no pattern, minimum or format keywords', function () {
    $json = json_encode(SchemaBuilder::for(InvoiceData::class));

    expect($json)->not->toContain('"pattern"')->not->toContain('"minimum"')->not->toContain('"format"');
});
