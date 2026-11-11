<?php

namespace App\Ai\Structured;

use Illuminate\Support\MessageBag;
use RuntimeException;

final class StructuredOutputFailed extends RuntimeException
{
    public function __construct(
        public readonly string $class,
        public readonly array $attempts,
        public readonly MessageBag $errors,
    ) {
        parent::__construct(sprintf(
            '%s still failed validation after %d attempts: %s',
            class_basename($class),
            count($attempts),
            implode(' ', $errors->all()),
        ));
    }
}
