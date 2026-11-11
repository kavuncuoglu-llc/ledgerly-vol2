<?php

namespace App\Ai\Triage;

final readonly class Draft
{
    public function __construct(public string $text, public bool $citationsOk, public array $sources = []) {}
}
