<?php

// A Modelfile is a list of directives, one per line, with # comments and triple-quoted values. Enough of a parser to test the files in ops/ollama/ by structure.
function parseModelfile(string $path): array
{
    $text = (string) file_get_contents($path);

    $directives = ['FROM' => [], 'PARAMETER' => [], 'TEMPLATE' => [], 'SYSTEM' => [], 'ADAPTER' => [], 'LICENSE' => []];

    $text = preg_replace_callback('/(\w+)\s+"""(.*?)"""/s', function (array $match) use (&$directives) {
        $directives[strtoupper($match[1])][] = trim($match[2]);

        return '';
    }, $text);

    foreach (preg_split('/\R/', (string) $text) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$directive, $value] = array_pad(preg_split('/\s+/', $line, 2), 2, '');

        $directives[strtoupper($directive)][] = trim($value);
    }

    $parameters = [];

    foreach ($directives['PARAMETER'] as $parameter) {
        [$name, $value] = preg_split('/\s+/', $parameter, 2);

        $parameters[$name][] = trim($value, '"');
    }

    $directives['PARAMETER'] = $parameters;

    return $directives;
}
