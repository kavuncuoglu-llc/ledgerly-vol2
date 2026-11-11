<?php

namespace App\Ai\Privacy\Detectors;

use App\Ai\Privacy\Detector;
use App\Ai\Privacy\Span;
use libphonenumber\Leniency;
use libphonenumber\PhoneNumberMatch;
use libphonenumber\PhoneNumberUtil;

// A phone number is a parsing problem, not a regular expression: libphonenumber finds the candidates and accepts only numbers that are valid for their region. A bare invoice number is not.
final class PhoneNumber implements Detector
{
    public function __construct(private string $region = 'US') {}

    public static function fromConfig(): self
    {
        return new self((string) config('ai.privacy.phone_region', 'US'));
    }

    public function find(string $text): array
    {
        $spans = [];

        foreach (PhoneNumberUtil::getInstance()->findNumbers($text, $this->region, Leniency::VALID()) as $match) {
            $spans[] = $this->spanFor($text, $match);
        }

        return $spans;
    }

    private function spanFor(string $text, PhoneNumberMatch $match): Span
    {
        $offset = strlen(mb_substr($text, 0, $match->start()));         // libphonenumber counts characters; substr_replace counts bytes

        return new Span('PHONE', $offset, strlen($match->rawString()), $match->rawString());
    }
}
