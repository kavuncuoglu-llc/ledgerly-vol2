<?php

namespace App\Ai\Retrieval\Eval;

use InvalidArgumentException;

final class GoldenSet
{
    /** @return GoldenQuestion[] */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Golden set not found: {$path}");
        }

        $questions = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $number => $line) {
            $row = json_decode($line, true);

            if (! is_array($row) || ! isset($row['id'], $row['question'], $row['articles'])) {
                throw new InvalidArgumentException('Golden set line '.($number + 1).' needs id, question and articles.');
            }

            $questions[] = new GoldenQuestion(
                id: (int) $row['id'],
                question: (string) $row['question'],
                articles: array_values($row['articles']),
                headings: array_values($row['headings'] ?? []),
                kind: (string) ($row['kind'] ?? 'plain'),
            );
        }

        return $questions;
    }
}
