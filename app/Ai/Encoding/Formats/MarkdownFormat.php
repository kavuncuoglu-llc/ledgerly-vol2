<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class MarkdownFormat implements Format
{
    public function describe(): string
    {
        return 'Data below is a Markdown table: a header row of field names, then one row per line.';
    }

    public function write(array $rows, string $key): string
    {
        if ($rows === []) {
            return '';
        }

        $fields = array_keys($rows[0]);

        $lines = [
            '| '.implode(' | ', $fields).' |',
            '|'.str_repeat('---|', count($fields)),
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map($this->cell(...), $row)).' |';   // unaligned: padding spaces are tokens
        }

        return implode("\n", $lines);
    }

    public function read(string $text): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($text)), fn (string $line) => $line !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $fields = $this->cells($lines[0]);

        return array_map(
            fn (string $line) => array_combine($fields, $this->cells($line)),
            array_slice($lines, 2),
        );
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return str_replace(['\\', '|', "\n", "\r"], ['\\\\', '\\|', ' ', ' '], (string) $value);
    }

    private function cells(string $line): array
    {
        $line = substr(trim($line), 1, -1);

        $cells = [];
        $current = '';

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $character = $line[$i];

            if ($character === '\\' && $i + 1 < $length) {
                $current .= $line[++$i];

                continue;
            }

            if ($character === '|') {
                $cells[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $cells[] = trim($current);

        return $cells;
    }
}
