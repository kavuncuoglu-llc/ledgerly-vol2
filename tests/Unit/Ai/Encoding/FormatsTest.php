<?php

use App\Ai\Encoding\Formats\JsonFormat;
use App\Ai\Encoding\Formats\MarkdownFormat;
use App\Ai\Encoding\Formats\ToonFormat;
use App\Ai\Encoding\Formats\TsvFormat;
use App\Ai\Encoding\Formats\YamlFormat;

function invoiceRows(): array
{
    return [
        ['number' => 'INV-4471', 'status' => 'overdue', 'due' => '2026-04-02', 'total' => 1284.5, 'days_overdue' => 18],
        ['number' => 'INV-4472', 'status' => 'paid', 'due' => '2026-04-15', 'total' => 640, 'days_overdue' => 0],
        ['number' => 'INV-4480', 'status' => 'sent', 'due' => '2026-05-01', 'total' => 2210.75, 'days_overdue' => 0],
    ];
}

function awkwardRows(): array
{
    return [
        ['id' => 'T-1', 'text' => 'Paid late, waive fee once', 'note' => 'He said "no"'],
        ['id' => 'T-2', 'text' => 'pipe | inside', 'note' => 'back\\slash'],
        ['id' => 'T-3', 'text' => 'tab	inside', 'note' => 'plain'],
        ['id' => 'T-4', 'text' => 'true', 'note' => '12'],
    ];
}

function stringify(array $rows): array
{
    return array_map(fn (array $row) => array_map(fn ($v) => $v === null ? '' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), $row), $rows);
}

describe('toon', function () {
    it('writes the Figure 12-4 tabular form', function () {
        expect((new ToonFormat)->write(invoiceRows(), 'invoices'))->toBe(<<<'TOON'
        invoices[3]{number,status,due,total,days_overdue}:
          INV-4471,overdue,2026-04-02,1284.5,18
          INV-4472,paid,2026-04-15,640,0
          INV-4480,sent,2026-05-01,2210.75,0
        TOON);
    });

    it('round-trips with types intact', function () {
        $format = new ToonFormat;

        expect($format->read($format->write(invoiceRows(), 'invoices')))->toEqual(invoiceRows());
    });

    it('quotes only what needs it and still round-trips', function () {
        $format = new ToonFormat;

        $text = $format->write(awkwardRows(), 'tickets');

        expect($text)->toContain('"Paid late, waive fee once"')
            ->and($format->read($text))->toEqual(awkwardRows());
    });

    it('can use a tab delimiter and says so in the description', function () {
        $format = new ToonFormat("\t");

        expect($format->describe())->toContain('tab-separated')
            ->and($format->read($format->write(invoiceRows(), 'invoices')))->toEqual(invoiceRows());
    });

    it('keeps null as null', function () {
        $format = new ToonFormat;

        $rows = [['id' => 'T-10423', 'invoice' => null]];

        expect($format->read($format->write($rows, 'tickets')))->toBe($rows);
    });
});

describe('tsv', function () {
    it('writes the header once and one tab-separated line per row', function () {
        expect((new TsvFormat)->write(invoiceRows(), 'invoices'))->toBe(implode("\n", [
            "number\tstatus\tdue\ttotal\tdays_overdue",
            "INV-4471\toverdue\t2026-04-02\t1284.5\t18",
            "INV-4472\tpaid\t2026-04-15\t640\t0",
            "INV-4480\tsent\t2026-05-01\t2210.75\t0",
        ]));
    });

    it('round-trips as strings, including values that would break the grid', function () {
        $format = new TsvFormat;

        expect($format->read($format->write(invoiceRows(), 'invoices')))->toBe(stringify(invoiceRows()))
            ->and($format->read($format->write(awkwardRows(), 'tickets')))->toBe(stringify(awkwardRows()));
    });

    it('writes null as an empty cell', function () {
        $format = new TsvFormat;

        expect($format->write([['id' => 'T-1', 'invoice' => null]], 'tickets'))->toBe("id\tinvoice\nT-1\t")
            ->and($format->read("id\tinvoice\nT-1\t"))->toBe([['id' => 'T-1', 'invoice' => '']]);
    });

    it('writes nothing for no rows', function () {
        expect((new TsvFormat)->write([], 'invoices'))->toBe('')
            ->and((new TsvFormat)->read(''))->toBe([]);
    });
});

describe('markdown', function () {
    it('writes an unaligned table', function () {
        expect((new MarkdownFormat)->write(invoiceRows(), 'invoices'))->toBe(implode("\n", [
            '| number | status | due | total | days_overdue |',
            '|---|---|---|---|---|',
            '| INV-4471 | overdue | 2026-04-02 | 1284.5 | 18 |',
            '| INV-4472 | paid | 2026-04-15 | 640 | 0 |',
            '| INV-4480 | sent | 2026-05-01 | 2210.75 | 0 |',
        ]));
    });

    it('round-trips as strings, escaping pipes and backslashes', function () {
        $format = new MarkdownFormat;

        expect($format->read($format->write(invoiceRows(), 'invoices')))->toBe(stringify(invoiceRows()))
            ->and($format->read($format->write(awkwardRows(), 'tickets')))->toBe(stringify(awkwardRows()));
    });

    it('turns a newline inside a cell into a space', function () {
        $format = new MarkdownFormat;

        expect($format->read($format->write([['id' => 'T-1', 'text' => "line one\nline two"]], 'tickets')))->toBe([['id' => 'T-1', 'text' => 'line one line two']]);
    });
});

describe('json', function () {
    it('round-trips compact and pretty, with the same tokens of information', function () {
        foreach ([new JsonFormat, new JsonFormat(pretty: true)] as $format) {
            expect($format->read($format->write(invoiceRows(), 'invoices')))->toEqual(invoiceRows());
        }
    });

    it('is compact by default and indented when asked', function () {
        expect((new JsonFormat)->write([['a' => 1]], 'rows'))->toBe('{"rows":[{"a":1}]}')
            ->and((new JsonFormat(pretty: true))->write([['a' => 1]], 'rows'))->toContain("\n    ");
    });
});

describe('yaml', function () {
    it('writes one list item per row with the dash on the first key', function () {
        expect((new YamlFormat)->write(invoiceRows(), 'invoices'))->toStartWith("invoices:\n  - number: INV-4471\n    status: overdue\n");
    });

    it('quotes the scalars YAML would misread and round-trips', function () {
        $format = new YamlFormat;

        $rows = [['answer' => 'no', 'empty' => '', 'nothing' => null, 'colon' => 'a: b', 'count' => '12', 'total' => 12.5]];

        expect($format->read($format->write($rows, 'rows')))->toBe($rows)
            ->and($format->read($format->write(invoiceRows(), 'invoices')))->toEqual(invoiceRows());
    });
});
