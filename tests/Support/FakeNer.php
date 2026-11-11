<?php

namespace Tests\Support;

use App\Ai\Privacy\Ner;
use App\Ai\Privacy\NerUnavailable;
use App\Ai\Privacy\Span;

// Stands in for Presidio: it knows the names a test tells it about, and it can be switched off. No test needs the real service.
final class FakeNer implements Ner
{
    public bool $up = true;

    public bool $failsWhenAsked = false;                    // says it is up, then does not answer: it went away between the check and the call

    /** @var array<string, string> value => type */
    public array $known = [];

    public int $calls = 0;

    public static function knowing(array $known): self
    {
        $ner = new self;

        $ner->known = $known;

        return $ner;
    }

    public function available(): bool
    {
        return $this->up;
    }

    public function find(string $text): array
    {
        $this->calls++;

        if (! $this->up || $this->failsWhenAsked) {
            throw new NerUnavailable('The fake name detector is switched off.');
        }

        $spans = [];

        foreach ($this->known as $value => $type) {
            $offset = 0;

            while (($at = strpos($text, $value, $offset)) !== false) {
                $spans[] = new Span($type, $at, strlen($value), $value);

                $offset = $at + strlen($value);
            }
        }

        return $spans;
    }
}
