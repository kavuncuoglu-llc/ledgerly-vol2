<?php

namespace App\Ai\Privacy\Detectors;

use App\Ai\Privacy\Detector;
use App\Ai\Privacy\Span;

// A pattern, then two checks: the domain is made of valid labels with at least one dot, and it is not one of the app's own addresses (support@ledgerly.test is not a customer's).
final class EmailAddress implements Detector
{
    public function __construct(private array $ownDomains = []) {}

    public static function fromConfig(): self
    {
        return new self((array) config('ai.privacy.own_domains', []));
    }

    public function find(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@([A-Z0-9](?:[A-Z0-9\-]*[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9\-]*[A-Z0-9])?)*\.[A-Z]{2,})/i', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $spans = [];

        foreach ($m as $hit) {
            if ($this->isOwn($hit[1][0])) {
                continue;
            }

            $spans[] = new Span('EMAIL', $hit[0][1], strlen($hit[0][0]), $hit[0][0]);
        }

        return $spans;
    }

    private function isOwn(string $domain): bool
    {
        return in_array(strtolower($domain), array_map('strtolower', $this->ownDomains), true);
    }
}
