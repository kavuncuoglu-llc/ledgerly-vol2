<?php

namespace App\Ai\Parsing;

final class ParserRouter implements DocumentParser
{
    /** @param DocumentParser[] $parsers */
    public function __construct(private array $parsers) {}

    public function supports(string $extension): bool
    {
        return $this->parserFor($extension) !== null;
    }

    public function parse(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $parser = $this->parserFor($extension)
            ?? throw new ParsingFailed("No parser for .{$extension} files ({$path}).");

        return $parser->parse($path);
    }

    private function parserFor(string $extension): ?DocumentParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports(strtolower($extension))) {
                return $parser;
            }
        }

        return null;
    }
}
