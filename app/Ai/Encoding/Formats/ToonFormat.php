<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Context\Encoders\ToonEncoder;
use App\Ai\Encoding\Format;

final class ToonFormat implements Format
{
    public function __construct(private string $delimiter = ',', private ?ToonEncoder $encoder = null) {}

    public function describe(): string
    {
        return match ($this->delimiter) {
            "\t" => 'Data below is in TOON format: `key[count]{fields}:` then one tab-separated row per line.',
            '|' => 'Data below is in TOON format: `key[count]{fields}:` then one pipe-separated row per line.',
            default => 'Data below is in TOON format: `key[count]{fields}:` then one comma-separated row per line.',
        };
    }

    public function write(array $rows, string $key): string
    {
        return $this->encoder()->encode([$key => $rows]);
    }

    public function read(string $text): array
    {
        $decoded = $this->encoder()->decode($text);

        return array_values(reset($decoded) ?: []);
    }

    // Chapter 48: the encoder is the one pinned in config unless a format is built for a particular spec version (compact-v1 is the 3.3 profile Volume 1 shipped).
    private function encoder(): ToonEncoder
    {
        return $this->encoder ?? ToonEncoder::pinned($this->delimiter);
    }
}
