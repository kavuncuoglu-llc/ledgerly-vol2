<?php

namespace App\Ai\Parsing;

final class MarkdownParser implements DocumentParser
{
    public function supports(string $extension): bool
    {
        return in_array($extension, ['md', 'markdown'], true);
    }

    public function parse(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
