<?php

namespace App\Ai\Conversations;

final class SummaryNote
{
    public static function render(string $summary): string
    {
        return "Earlier in this conversation:\n{$summary}";
    }
}
