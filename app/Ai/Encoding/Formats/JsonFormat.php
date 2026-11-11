<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class JsonFormat implements Format
{
    public function __construct(private bool $pretty = false) {}

    public function describe(): string
    {
        return $this->pretty
            ? 'Data below is in pretty-printed JSON.'
            : 'Data below is in compact JSON: one object per row.';
    }

    public function write(array $rows, string $key): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($this->pretty ? JSON_PRETTY_PRINT : 0);

        return json_encode([$key => $rows], $flags | JSON_THROW_ON_ERROR);
    }

    public function read(string $text): array
    {
        $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);

        return array_values(reset($decoded) ?: []);
    }
}
