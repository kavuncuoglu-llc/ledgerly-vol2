<?php

namespace App\Ai\Privacy;

// No PRESIDIO_URL: names and addresses cannot be screened, so a hosted call fails closed.
final class NullNer implements Ner
{
    public function available(): bool
    {
        return false;
    }

    public function find(string $text): array
    {
        throw new NerUnavailable('PRESIDIO_URL is not set.');
    }
}
