<?php

use App\Ai\Python\Contract;
use App\Ai\Python\ContractViolation;

function contractFixtures(string $schema, string $prefix): array
{
    return glob(dirname(__DIR__, 4)."/contracts/fixtures/{$schema}/{$prefix}-*.json");
}

foreach (['embed-batch.v1', 'embed-result.v1'] as $schema) {
    foreach (contractFixtures($schema, 'valid') as $path) {
        it("accepts {$schema}/".basename($path), function () use ($schema, $path) {
            Contract::assertValid($schema, json_decode(file_get_contents($path), true));

            expect(Contract::isValid($schema, json_decode(file_get_contents($path), true)))->toBeTrue();
        });
    }

    foreach (contractFixtures($schema, 'invalid') as $path) {
        it("rejects {$schema}/".basename($path), function () use ($schema, $path) {
            expect(fn () => Contract::assertValid($schema, json_decode(file_get_contents($path), true)))
                ->toThrow(ContractViolation::class, $schema);
        });
    }
}

it('has both kinds of fixture for each contract', function () {
    foreach (['embed-batch.v1', 'embed-result.v1'] as $schema) {
        expect(contractFixtures($schema, 'valid'))->not->toBeEmpty()
            ->and(contractFixtures($schema, 'invalid'))->not->toBeEmpty();
    }
});

it('names every fixture valid or invalid, so a new file cannot be skipped by mistake', function () {
    foreach (glob(dirname(__DIR__, 4).'/contracts/fixtures/*/*.json') as $path) {
        expect(preg_match('/^(valid|invalid)-/', basename($path)))->toBe(1);
    }
});

it('refuses a schema name that is not a contract file name', function () {
    expect(fn () => Contract::assertValid('../../.env', []))->toThrow(InvalidArgumentException::class);
});

it('carries the violation, with the schema it broke', function () {
    try {
        Contract::assertValid('embed-batch.v1', ['schema' => 'embed-batch.v1']);
    } catch (ContractViolation $e) {
        expect($e->schema)->toBe('embed-batch.v1')
            ->and($e->errors)->not->toBeEmpty();

        return;
    }

    $this->fail('expected a ContractViolation');
});

it('puts the schema name in every contract file, and keeps the $id the PHP validator registers', function () {
    foreach (['embed-batch.v1', 'embed-result.v1'] as $schema) {
        $document = json_decode(file_get_contents(base_path("contracts/{$schema}.json")), true);

        expect($document['properties']['schema'])->toBe(['const' => $schema])
            ->and($document['$id'])->toBe("https://ledgerly.test/contracts/{$schema}.json")
            ->and($document['additionalProperties'])->toBeFalse();
    }
});
