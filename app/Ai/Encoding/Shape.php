<?php

namespace App\Ai\Encoding;

use Illuminate\Support\Collection;

interface Shape
{
    /** @return array<string, callable> field name => formatter(row) */
    public function fields(): array;

    public function header(Collection $rows): ?string;

    public function limit(): int;
}
