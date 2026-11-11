<?php

namespace App\Evals;

// Chapter 29, Topic 8: a captured question is customer text and is redacted before it is saved as a case. This is the minimum (emails, phone numbers); Chapter 34 replaces it with the full pipeline.
final class Redactor
{
    public static function scrub(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);

        return preg_replace('/\+?\d[\d\s().\-]{7,}\d/', '[phone]', $text);
    }
}
