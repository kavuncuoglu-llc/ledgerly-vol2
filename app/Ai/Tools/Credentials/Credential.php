<?php

namespace App\Ai\Tools\Credentials;

final readonly class Credential
{
    public function __construct(
        public string $label,
        public array $scopes,
        public string $secret,
    ) {}

    public function __toString(): string
    {
        return $this->label;                                       // the secret is never part of a log line, a result or a span
    }
}
