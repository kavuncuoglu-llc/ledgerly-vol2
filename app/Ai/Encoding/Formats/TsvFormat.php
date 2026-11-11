<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class TsvFormat implements Format
{
    public function describe(): string
    {
        return 'Data below is tab-separated with a header row of field names.';
    }

    public function write(array $rows, string $key): string
    {
        if ($rows === []) {
            return '';
        }

        $lines = [implode("\t", array_keys($rows[0]))];

        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map($this->cell(...), $row));
        }

        return implode("\n", $lines);
    }

    public function read(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $records = $this->records($text);

        $fields = array_shift($records);

        return array_map(fn (array $record) => array_combine($fields, $record), $records);
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $text = (string) $value;

        if (preg_match('/[\t\n\r"]/', $text) === 1) {
            return '"'.str_replace('"', '""', $text).'"';   // quoted only when the value would break the grid
        }

        return $text;
    }

    private function records(string $text): array
    {
        $handle = fopen('php://memory', 'r+');

        fwrite($handle, $text);
        rewind($handle);

        $records = [];

        while (($record = fgetcsv($handle, null, "\t", '"', '')) !== false) {
            $records[] = $record;
        }

        fclose($handle);

        return $records;
    }
}
