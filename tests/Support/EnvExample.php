<?php

function envExampleValue(string $name): string
{
    preg_match('/^'.preg_quote($name, '/').'=(.*)$/m', file_get_contents(base_path('.env.example')), $match);

    return $match[1] ?? '';
}
