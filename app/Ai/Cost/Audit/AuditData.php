<?php

namespace App\Ai\Cost\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// The week's calls, priced, rolled up into units, with the outcome of each, and the few facts about messages the retry detector needs.
final class AuditData
{
    /**
     * @param  Collection<int, AuditCall>  $calls
     * @param  Collection<int, Unit>  $units
     */
    public function __construct(
        public readonly CarbonImmutable $since,
        public readonly Collection $calls,
        public readonly Collection $units,
        public readonly int $staleAnswers,
        public readonly int $automatedClassifications,
    ) {}

    public function spend(): float
    {
        return $this->calls->sum('usd');
    }

    public function example(AuditCall $call): string
    {
        return sprintf('call %d  %s  %s  $%.4f  trace %s', $call->id, $call->feature, number_format($call->inputTokens).' in', $call->usd, $this->trace($call->traceId));
    }

    public function trace(?string $traceId): string
    {
        return $traceId === null
            ? 'none recorded'
            : substr($traceId, 0, 8).'…';
    }

    public function percentile(Collection $values, float $percentile): float
    {
        $sorted = $values->sort()->values();

        if ($sorted->isEmpty()) {
            return 0.0;
        }

        return (float) $sorted[(int) max(0, ceil($percentile * $sorted->count()) - 1)];
    }

    public function median(Collection $values): float
    {
        return $this->percentile($values, 0.5);
    }
}
