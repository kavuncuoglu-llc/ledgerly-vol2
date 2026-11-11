<?php

namespace App\Ai\Privacy;

// Names and addresses need a model: this is the seam to it. The app ships one implementation (Presidio, in the privacy profile); nothing else in the app knows which.
interface Ner
{
    public function available(): bool;

    /**
     * @return Span[]
     *
     * @throws NerUnavailable when the service does not answer
     */
    public function find(string $text): array;
}
