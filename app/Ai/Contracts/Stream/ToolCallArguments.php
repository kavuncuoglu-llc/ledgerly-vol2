<?php

namespace App\Ai\Contracts\Stream;

final readonly class ToolCallArguments implements StreamEvent
{
    public function __construct(public int $index, public string $fragment) {}   // a piece of the JSON arguments; they are complete only in the final ChatResponse
}
