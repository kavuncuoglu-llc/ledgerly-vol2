<?php

use App\Ai\Context\Encoders\ToonEncoder;
use App\Ai\Encoding\DataEncoder;
use App\Ai\Encoding\Formats\ToonFormat;

// Chapter 48: the app's TOON format writes through the encoder pinned in config; compact-v1 is the 3.3 profile Volume 1 shipped, kept so the two can be compared.

it('registers the pinned format as toon and the older profile as compact-v1', function () {
    expect(app(DataEncoder::class)->formats())->toContain('toon', 'compact-v1');
});

it('writes with the spec version in config', function () {
    $rows = [['number' => 'INV-1', 'total' => 118.0]];

    config(['ai.context.toon.spec_version' => '4.4']);

    $pinned = (new ToonFormat)->write($rows, 'invoices');

    expect($pinned)->toBe(ToonEncoder::forVersion('4.4')->encode(['invoices' => $rows]));
});

it('differs between the 3.3 profile and spec 4.4 where the spec changed: an empty list', function () {
    $formats = app(DataEncoder::class);

    expect($formats->format('compact-v1')->write([], 'invoices'))->toBe('invoices[0]:')
        ->and($formats->format('toon')->write([], 'invoices'))->toBe('invoices: []');
});

it('agrees between them for the tabular rows the assistant sends', function () {
    $rows = [['number' => 'INV-4471', 'status' => 'overdue', 'total' => 1284.5], ['number' => 'INV-4472', 'status' => 'sent', 'total' => 100.0]];

    $formats = app(DataEncoder::class);

    expect($formats->format('toon')->write($rows, 'invoices'))->toBe($formats->format('compact-v1')->write($rows, 'invoices'));
});

it('reads back what it wrote, strictly', function () {
    $format = new ToonFormat;

    $rows = [['number' => 'INV-1', 'total' => 118.5]];

    expect($format->read($format->write($rows, 'invoices')))->toBe($rows);
});
