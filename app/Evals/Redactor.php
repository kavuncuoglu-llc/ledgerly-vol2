<?php

namespace App\Evals;

use App\Ai\Privacy\PersonalDataDetector;

// Chapter 29, Topic 8: a captured question is customer text and is redacted before it is saved as a case. Chapter 34 replaces the two regular expressions this used to be with the full pipeline: validated patterns, and names when the privacy profile is up.
// Irreversible, like a log line: "[email]", "[phone]", "[card]", "[person]".
final class Redactor
{
    public static function scrub(string $text): string
    {
        return app(PersonalDataDetector::class)->maskWithNames($text);
    }
}
