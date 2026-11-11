<?php

namespace App\Ai\Events;

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolResult;
use Illuminate\Foundation\Events\Dispatchable;

final class ToolCalled
{
    use Dispatchable;

    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ToolResult $result,
        public readonly ToolContext $context,
        public readonly string $decision = 'allowed',
        public readonly ?int $durationMs = null,
    ) {}
}
