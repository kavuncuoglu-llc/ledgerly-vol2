<?php

namespace App\Ai\Parsing;

interface DocumentParser
{
    public function supports(string $extension): bool;

    /** Markdown with correct heading levels, tables as Markdown tables and boilerplate gone */
    public function parse(string $path): string;
}
