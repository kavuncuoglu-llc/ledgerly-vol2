<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;
use Symfony\Component\Yaml\Yaml;

final class YamlFormat implements Format
{
    public function describe(): string
    {
        return 'Data below is in YAML: one list item per row.';
    }

    public function write(array $rows, string $key): string
    {
        $lines = ["{$key}:"];

        foreach ($rows as $row) {
            $first = true;

            foreach ($row as $field => $value) {
                $lines[] = ($first ? '  - ' : '    ').$field.': '.$this->scalar($value);

                $first = false;
            }
        }

        return implode("\n", $lines);   // "- number: INV-4471" on one line; the parser's own dump puts the dash alone
    }

    public function read(string $text): array
    {
        $decoded = Yaml::parse($text);

        return array_values(reset($decoded) ?: []);
    }

    private function scalar(mixed $value): string
    {
        return trim(Yaml::dump($value, 0, 2));   // quotes only what YAML would misread: "no", "null", "12", ""
    }
}
