<?php

namespace App\Ai\Packages;

// Chapter 48, Topic 7: one audit, as lines a decision record can quote. `ok` passes its gate, `warn` is something a person reads, `fail` stops the package at that gate, `info` is neither.
final class PackageAudit
{
    /** @var array<string, array{0: string, 1: string}> label => [status, text] */
    private array $lines = [];

    /** @var list<string> */
    private array $open = [];

    /** @var array<string, mixed> */
    private array $signals = [];

    public function __construct(public readonly string $package, public readonly string $version, public readonly bool $installed) {}

    public function line(string $label, string $status, string $text): self
    {
        $this->lines[$label] = [$status, $text];

        return $this;
    }

    public function open(string $item): self
    {
        if (! in_array($item, $this->open, true)) {
            $this->open[] = $item;
        }

        return $this;
    }

    public function signal(string $key, mixed $value): self
    {
        $this->signals[$key] = $value;

        return $this;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<string> */
    public function openItems(): array
    {
        return $this->open;
    }

    public function signals(): array
    {
        return $this->signals;
    }

    public function status(string $label): string
    {
        return $this->lines[$label][0] ?? 'info';
    }

    public function failed(): bool
    {
        return collect($this->lines)->contains(fn (array $line) => $line[0] === 'fail');
    }

    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'version' => $this->version,
            'installed' => $this->installed,
            'lines' => array_map(fn (array $line) => ['status' => $line[0], 'text' => $line[1]], $this->lines),
            'open_items' => $this->open,
            'signals' => $this->signals,
        ];
    }
}
