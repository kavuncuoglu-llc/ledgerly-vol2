<?php

namespace App\Ai\Structured;

use App\Ai\Exceptions\AiException;
use Illuminate\Support\MessageBag;

// Chapter 31, Topic 2: the message names the class and the fields that failed, never what the model wrote or what the validator quoted of it. The attempts stay on the object for the caller that handles it.
final class StructuredOutputFailed extends AiException
{
    public function __construct(
        public readonly string $class,
        public readonly array $attempts,
        public readonly MessageBag $errors,
    ) {
        parent::__construct(sprintf(
            '%s still failed validation after %d attempts (fields: %s)',
            class_basename($class),
            count($attempts),
            implode(', ', $errors->keys()),
        ));
    }
}
