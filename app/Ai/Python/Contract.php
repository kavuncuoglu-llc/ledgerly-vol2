<?php

namespace App\Ai\Python;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

// PHP validates against the same file Python's models were generated from (Chapter 27, Topic 5).
final class Contract
{
    public static function assertValid(string $schema, array $payload): void
    {
        if (preg_match('/^[a-z0-9-]+\.v\d+$/', $schema) !== 1) {
            throw new InvalidArgumentException("Not a contract name: {$schema}");
        }

        $validator = new Validator;
        $id = "https://ledgerly.test/contracts/{$schema}.json";
        $validator->resolver()->registerFile($id, base_path("contracts/{$schema}.json"));

        $result = $validator->validate(json_decode(json_encode($payload)), $id);

        if (! $result->isValid()) {
            throw new ContractViolation($schema, (new ErrorFormatter)->format($result->error()));
        }
    }

    public static function isValid(string $schema, array $payload): bool
    {
        try {
            self::assertValid($schema, $payload);
        } catch (ContractViolation) {
            return false;
        }

        return true;
    }
}
