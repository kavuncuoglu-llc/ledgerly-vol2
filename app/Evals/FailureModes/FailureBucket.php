<?php

namespace App\Evals\FailureModes;

// Chapter 38, Topic 8, Figure 38-8: five causes, and the fix each one points at. Only the first is a fine-tuning problem.
enum FailureBucket: string
{
    case Behavior = 'behavior and format';
    case Retrieval = 'retrieval miss';
    case Arithmetic = 'arithmetic';
    case Coverage = 'coverage gap';
    case Unclear = 'unclear (judged, then reviewed)';

    public function goesTo(): string
    {
        return match ($this) {
            self::Behavior => 'fine-tuning (Section 7)',
            self::Retrieval => 'retrieval tuning (Volume 1 Ch 16)',
            self::Arithmetic => 'a proration tool, not training',
            self::Coverage => 'help-center article requested',
            self::Unclear => 'a person',
        };
    }

    public static function fromJudgement(string $text): ?self
    {
        $text = strtolower($text);

        foreach (['behavior' => self::Behavior, 'retrieval' => self::Retrieval, 'arithmetic' => self::Arithmetic, 'coverage' => self::Coverage] as $word => $bucket) {
            if (str_contains($text, $word)) {
                return $bucket;
            }
        }

        return null;
    }
}
