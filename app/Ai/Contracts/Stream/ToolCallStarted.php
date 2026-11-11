<?php

namespace App\Ai\Contracts\Stream;

final readonly class ToolCallStarted implements StreamEvent
{
    public function __construct(public string $name, public int $index) {}
}
