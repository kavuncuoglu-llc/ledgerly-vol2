<?php

namespace App\Ai\Sensitive;

interface SensitiveClassifier
{
    // A decision when it is sure the message needs a person; null otherwise. It is never asked to clear a message the patterns held back.
    public function classify(string $message): ?RoutingDecision;
}
