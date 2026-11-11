<?php

namespace App\Ai\Python;

use RuntimeException;

final class ContractViolation extends RuntimeException
{
    public function __construct(public readonly string $schema, public readonly array $errors)
    {
        parent::__construct("{$schema} violated: ".json_encode($errors, JSON_UNESCAPED_SLASHES));
    }
}
