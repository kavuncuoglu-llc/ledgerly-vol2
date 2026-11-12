<?php

namespace App\Ai\Sensitive;

final class NullClassifier implements SensitiveClassifier
{
    public function classify(string $message): ?RoutingDecision
    {
        return null;
    }
}
