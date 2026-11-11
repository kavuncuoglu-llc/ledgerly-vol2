<?php

namespace App\Ai\Streaming;

use Closure;

// Fig 18-4: a model emits a token every 10 to 70 ms; a frame per token is fine for one user and a problem for a hundred. Flush every 50 ms or 20 tokens.
final class DeltaBatcher
{
    private string $buffer = '';

    private int $count = 0;

    private float $last;

    public function __construct(private Closure $flush, private int $maxMs = 50, private int $maxTokens = 20)
    {
        $this->last = microtime(true);
    }

    public function push(string $delta): void
    {
        $this->buffer .= $delta;

        $this->count++;

        if ($this->count >= $this->maxTokens) {
            $this->flushNow();

            return;
        }

        if ((microtime(true) - $this->last) * 1000 >= $this->maxMs) {
            $this->flushNow();
        }
    }

    public function flushNow(): void
    {
        if ($this->buffer === '') {
            return;
        }

        ($this->flush)($this->buffer);

        $this->buffer = '';
        $this->count = 0;
        $this->last = microtime(true);
    }
}
