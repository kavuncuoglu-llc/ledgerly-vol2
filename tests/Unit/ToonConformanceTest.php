<?php

use App\Ai\Context\Encoders\ToonDecodeError;
use App\Ai\Context\Encoders\ToonEncoder;
use Tests\Support\ToonFixtures;

// Chapter 48, Topic 4: the spec's own fixtures, against the pinned encoder. A library upgrade that changes what the app writes fails here, not in production prompts.

dataset('toon-fixtures', fn () => ToonFixtures::all(dirname(__DIR__).'/Fixtures/toon-spec'));

it('encodes and decodes exactly as each vendored spec version requires', function (string $version, string $category, mixed $input, mixed $expected, array $options, bool $shouldError) {
    $encoder = ToonEncoder::forVersion($version, (string) ($options['delimiter'] ?? ','), (int) ($options['indentSize'] ?? 2));

    if ($category === 'encode') {
        expect($encoder->encode($input))->toBe($expected);

        return;
    }

    if ($shouldError) {
        expect(fn () => $encoder->decode($input, strict: $options['strict'] ?? true))->toThrow(ToonDecodeError::class);

        return;
    }

    expect(json_decode(json_encode($encoder->decode($input, strict: $options['strict'] ?? true)), true))->toEqual(json_decode(json_encode($expected), true));
})->with('toon-fixtures');

it('writes the same text through ToonEncoder as the fixtures require for the default options', function () {
    $encoder = ToonEncoder::pinned();

    expect($encoder->encode(['invoices' => [['number' => '4471', 'amount' => 118], ['number' => '4472', 'amount' => 100]]]))
        ->toBe("invoices[2]{number,amount}:\n  \"4471\",118\n  \"4472\",100");
});

it('rejects malformed TOON in strict mode', function () {
    expect(fn () => ToonEncoder::pinned()->decode("invoices[3]{number,amount}:\n  4471,118\n  4472,100"))
        ->toThrow(ToonDecodeError::class);                      // header says 3 rows; there are 2
});

it('is pinned to a spec version that has fixtures, and runs them', function () {
    expect(config('ai.context.toon.spec_version'))->toBe('4.4')
        ->and(ToonEncoder::pinned()->specVersion())->toBe('4.4')
        ->and(ToonFixtures::versions(base_path('tests/Fixtures/toon-spec')))->toContain(config('ai.context.toon.spec_version'))
        ->and(count(ToonFixtures::load(base_path('tests/Fixtures/toon-spec/'.config('ai.context.toon.spec_version')))))->toBe(564);
});

it('refuses a spec version the installed library does not implement', function () {
    expect(fn () => ToonEncoder::forVersion('2.0'))->toThrow(InvalidArgumentException::class, 'is not a TOON spec version');
});
