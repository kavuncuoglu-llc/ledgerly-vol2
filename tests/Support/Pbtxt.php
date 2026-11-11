<?php

// A small reader for the protobuf text format of Triton's config.pbtxt: enough to check structure (names, backends, tensors, ensemble maps), not a validator. Comments start with #.
// Every field comes back as a list, because the format repeats a key to repeat a field (several input_map entries, several steps).
function parsePbtxt(string $text): array
{
    $tokens = tokenizePbtxt($text);
    $position = 0;

    return parsePbtxtMessage($tokens, $position, null);
}

function tokenizePbtxt(string $text): array
{
    $text = preg_replace('/#[^\n]*/', '', $text);

    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,"]+/', $text, $matches);

    return $matches[0];
}

function parsePbtxtMessage(array $tokens, int &$i, ?string $closer): array
{
    $message = [];

    while ($i < count($tokens)) {
        $token = $tokens[$i];

        if ($closer !== null && $token === $closer) {
            $i++;

            return $message;
        }

        if ($token === ',') {
            $i++;

            continue;
        }

        $key = $token;
        $i++;

        if (($tokens[$i] ?? null) === ':') {
            $i++;
        }

        foreach (parsePbtxtValues($tokens, $i) as $value) {
            $message[$key][] = $value;
        }
    }

    return $message;
}

function parsePbtxtValues(array $tokens, int &$i): array
{
    $token = $tokens[$i] ?? null;

    if ($token === '{') {
        $i++;

        return [parsePbtxtMessage($tokens, $i, '}')];
    }

    if ($token === '[') {
        $i++;
        $values = [];

        while (($tokens[$i] ?? ']') !== ']') {
            if ($tokens[$i] === ',') {
                $i++;

                continue;
            }

            array_push($values, ...parsePbtxtValues($tokens, $i));
        }

        $i++;

        return $values;
    }

    $i++;

    return [parsePbtxtScalar((string) $token)];
}

function parsePbtxtScalar(string $token): int|float|string|bool
{
    if (str_starts_with($token, '"')) {
        return stripcslashes(substr($token, 1, -1));
    }

    if (is_numeric($token)) {
        return str_contains($token, '.') ? (float) $token : (int) $token;
    }

    return match ($token) {
        'true' => true,
        'false' => false,
        default => $token,
    };
}
