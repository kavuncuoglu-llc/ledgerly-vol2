<?php

namespace App\Ai\Privacy;

// One piece of personal data found in a text. Offsets and lengths are in bytes, so substr_replace() can swap it out.
final readonly class Span
{
    public function __construct(
        public string $type,
        public int $offset,
        public int $length,
        public string $text,
    ) {}

    public function end(): int
    {
        return $this->offset + $this->length;
    }

    public function overlaps(self $other): bool
    {
        return $this->offset < $other->end() && $other->offset < $this->end();
    }
}
