<?php

namespace App\Ai\Cost\Audit;

final readonly class SignatureResult
{
    /** @param  array<int, string>  $examples  lines, each ending in a trace id where there is one */
    public function __construct(
        public string $name,
        public bool $flagged,
        public string $summary,
        public array $examples = [],
        public ?string $next = null,
    ) {}

    public static function clear(string $name, string $summary): self
    {
        return new self($name, false, $summary);
    }

    public static function flagged(string $name, string $summary, array $examples = [], ?string $next = null): self
    {
        return new self($name, true, $summary, $examples, $next);
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'flagged' => $this->flagged, 'summary' => $this->summary, 'examples' => $this->examples, 'next' => $this->next];
    }
}
