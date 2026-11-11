<?php

namespace App\Ai\Streaming;

use Closure;

// What the agent loop tells the outside world while a reply is being generated: text as it arrives, and the name of a tool as soon as the model asks for one.
final class StreamListener
{
    public function __construct(private Closure $onDelta, private ?Closure $onToolStart = null) {}

    public function delta(string $text): void
    {
        ($this->onDelta)($text);
    }

    public function toolStarted(string $tool): void
    {
        if ($this->onToolStart === null) {
            return;
        }

        ($this->onToolStart)($tool);
    }
}
