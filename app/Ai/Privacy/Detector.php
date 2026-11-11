<?php

namespace App\Ai\Privacy;

interface Detector
{
    /** @return Span[] */
    public function find(string $text): array;
}
