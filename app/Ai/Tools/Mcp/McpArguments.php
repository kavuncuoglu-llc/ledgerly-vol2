<?php

namespace App\Ai\Tools\Mcp;

final readonly class McpArguments
{
    public function __construct(public array $arguments) {}

    public static function fromArray(array $d): self
    {
        return new self($d);
    }

    public static function rules(): array
    {
        return [];
    }
}
