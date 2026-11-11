<?php

namespace App\Ai\Registry;

final readonly class VerificationLine
{
    public function __construct(public string $subject, public bool $ok, public string $detail) {}

    public function text(): string
    {
        return str_pad($this->subject, 46).' '.($this->ok ? '' : 'FAILED · ').$this->detail;
    }
}
