<?php

namespace App\Ai\Security;

use App\Ai\Contracts\ChatRequest;

final class ExtractionGuard
{
    public const OVERLAP_WORDS = 12;

    public function check(string $answer, ChatRequest $request): ?string
    {
        $canary = (string) config('ai.security.canary');

        if ($canary !== '' && str_contains($answer, $canary)) {          // planted in every system message
            return 'canary';
        }

        $system = $this->squash($request->systemText());

        foreach ($this->shingles($this->squash($answer), self::OVERLAP_WORDS) as $run) {          // 12-word runs
            if (str_contains($system, $run)) {
                return 'system_overlap';
            }
        }

        return null;
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function shingles(string $text, int $n): iterable
    {
        $words = $text === ''
            ? []
            : explode(' ', $text);

        for ($i = 0; $i + $n <= count($words); $i++) {
            yield implode(' ', array_slice($words, $i, $n));
        }
    }
}
