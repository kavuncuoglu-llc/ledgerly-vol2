<?php

use App\Ai\Encoding\DataEncoder;
use App\Ai\Encoding\Formats\JsonFormat;
use App\Ai\Encoding\Formats\MarkdownFormat;
use App\Ai\Encoding\Formats\ToonFormat;
use App\Ai\Encoding\Formats\TsvFormat;
use App\Ai\Encoding\Shape;
use Illuminate\Support\Collection;

function pointShape(int $limit = 25, ?string $header = 'Amounts in USD.'): Shape
{
    return new class($limit, $header) implements Shape
    {
        public function __construct(private int $limit, private ?string $header) {}

        public function fields(): array
        {
            return [
                'name' => fn (array $row) => $row['name'],
                'amount' => fn (array $row) => round($row['amount'], 2),
            ];
        }

        public function header(Collection $rows): ?string
        {
            return $this->header;
        }

        public function limit(): int
        {
            return $this->limit;
        }
    };
}

function pointRows(int $count = 3): Collection
{
    return collect(range(1, $count))->map(fn (int $i) => ['id' => $i, 'name' => "row {$i}", 'amount' => $i * 10.456, 'secret' => 'never sent']);
}

function pointEncoder(): DataEncoder
{
    return new DataEncoder([
        'toon' => new ToonFormat,
        'tsv' => new TsvFormat,
        'markdown' => new MarkdownFormat,
        'json' => new JsonFormat,
    ]);
}

it('applies the shape, then writes the header, the format description and the data', function () {
    $text = pointEncoder()->encode(pointRows(), pointShape(), 'toon', key: 'points');

    expect($text)->toBe(implode("\n", [
        'Amounts in USD.',
        'Data below is in TOON format: `key[count]{fields}:` then one comma-separated row per line.',
        'points[3]{name,amount}:',
        '  row 1,10.46',
        '  row 2,20.91',
        '  row 3,31.37',
    ]));
});

it('never lets a column the shape did not select reach the text', function () {
    expect(pointEncoder()->encode(pointRows(), pointShape(), 'json'))->not->toContain('never sent')->not->toContain('"id"');
});

it('enforces the row limit and says rows were cut', function () {
    $text = pointEncoder()->encode(pointRows(5), pointShape(limit: 2), 'tsv');

    expect($text)->toContain('Showing 2 of 5 rows.')
        ->and(substr_count($text, "\nrow "))->toBe(2);
});

it('does not mention a cut when nothing was cut', function () {
    expect(pointEncoder()->encode(pointRows(2), pointShape(), 'tsv'))->not->toContain('Showing');
});

it('leaves the header out when the shape has none', function () {
    $text = pointEncoder()->encode(pointRows(1), pointShape(header: null), 'markdown');

    expect($text)->toStartWith('Data below is a Markdown table');
});

it('tells the model which format it is reading', function () {
    $encoder = pointEncoder();

    expect($encoder->encode(pointRows(1), pointShape(), 'tsv'))->toContain('tab-separated')
        ->and($encoder->encode(pointRows(1), pointShape(), 'markdown'))->toContain('Markdown table')
        ->and($encoder->encode(pointRows(1), pointShape(), 'json'))->toContain('compact JSON');
});

it('takes the default format from config', function () {
    config(['ai.encoding.default' => 'tsv']);

    expect(pointEncoder()->encode(pointRows(1), pointShape()))->toContain('tab-separated');

    config(['ai.encoding.default' => 'toon']);

    expect(pointEncoder()->encode(pointRows(1), pointShape()))->toContain('TOON format');
});

it('lets a feature override the default, even when its name has dots', function () {
    config(['ai.encoding.default' => 'toon', 'ai.encoding.features' => ['support.reply' => 'markdown']]);

    $encoder = pointEncoder();

    expect($encoder->encode(pointRows(1), pointShape(), feature: 'support.reply'))->toContain('Markdown table')
        ->and($encoder->encode(pointRows(1), pointShape(), feature: 'other.feature'))->toContain('TOON format')
        ->and($encoder->encode(pointRows(1), pointShape(), 'json', feature: 'support.reply'))->toContain('compact JSON');
});

it('refuses a format it does not have and names the ones it does', function () {
    expect(fn () => pointEncoder()->encode(pointRows(1), pointShape(), 'xml'))
        ->toThrow(InvalidArgumentException::class, "Unknown data format 'xml'. Available: toon, tsv, markdown, json.");
});
