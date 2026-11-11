<?php

namespace App\Ai\Structured;

use RuntimeException;

final class StructuredRefusal extends RuntimeException
{
    public function __construct(
        public readonly string $class,
        public readonly string $reason,
        public readonly array $attempts,
    ) {
        parent::__construct(class_basename($class).' was declined by the model: '.$reason);
    }
}
