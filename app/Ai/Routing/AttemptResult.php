<?php

namespace App\Ai\Routing;

use App\Ai\Context\AssembledPrompt;
use App\Ai\Tools\ToolRunResult;

// One role's attempt at a question, after the answer checks the app already runs on every answer (citations with one repair, the answer guard).
final readonly class AttemptResult
{
    public function __construct(
        public ToolRunResult $result,
        public AssembledPrompt $prompt,
        public bool $blocked,
        public array $findings,
        public string $provider = '',
    ) {}
}
