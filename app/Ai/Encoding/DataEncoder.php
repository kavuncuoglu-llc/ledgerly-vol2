<?php

namespace App\Ai\Encoding;

use Illuminate\Support\Collection;
use InvalidArgumentException;

final class DataEncoder
{
    public function __construct(private array $formats) {}   // ['toon' => ToonFormat, 'tsv' => TsvFormat, 'markdown' => ..., 'json' => ...]

    public function encode(Collection $rows, Shape $shape, ?string $format = null, string $key = 'rows', ?string $feature = null): string
    {
        $format ??= $this->formatFor($feature);

        $chosen = $this->format($format);

        $limited = $rows->take($shape->limit());

        $shaped = $limited->map(fn ($row) => array_map(fn ($f) => $f($row), $shape->fields()))->values()->all();

        $lines = [];

        if ($header = $shape->header($rows)) {
            $lines[] = $header;
        }

        if ($rows->count() > $limited->count()) {
            $lines[] = "Showing {$limited->count()} of {$rows->count()} rows.";
        }

        $lines[] = $chosen->describe();                               // "Data below is in TOON format: a header with field names, then one row per line."
        $lines[] = $chosen->write($shaped, $key);

        return implode("\n", $lines);
    }

    public function format(string $name): Format
    {
        return $this->formats[$name] ?? throw new InvalidArgumentException("Unknown data format '{$name}'. Available: ".implode(', ', array_keys($this->formats)).'.');
    }

    /** @return string[] */
    public function formats(): array
    {
        return array_keys($this->formats);
    }

    private function formatFor(?string $feature): string
    {
        $perFeature = config('ai.encoding.features', [])[$feature] ?? null;   // feature names contain dots, so not a config path

        if ($perFeature) {
            return $perFeature;
        }

        return config('ai.encoding.default', 'toon');
    }
}
