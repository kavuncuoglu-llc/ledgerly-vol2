<?php

namespace App\Ai\Review;

use App\Ai\Drift\Drift;
use App\Evals\Scorecard\Scorecard;
use Illuminate\Support\Facades\Cache;
use Throwable;

// Chapter 50, Topic 9: the quarter's drift, golden-set and cost trends, read from what the app already measures. A figure with no data behind it says so; it is never filled in.
final class Trends
{
    public function __construct(private Drift $drift, private Scorecard $scorecard) {}

    /** @return array{drift: string, golden: string, cost: string, reconcile: string} */
    public function lines(string $feature = 'support.reply'): array
    {
        return [
            'drift' => $this->driftLine($feature),
            'golden' => $this->scorecardLine($feature, 'golden_quality_pass', 'golden pass', percent: true),
            'cost' => $this->scorecardLine($feature, 'cost_per_answer_usd', 'cost per answer', percent: false),
            'reconcile' => $this->reconcileLine(),
        ];
    }

    private function driftLine(string $feature): string
    {
        try {
            $report = $this->drift->report($feature, 7, 28);
        } catch (Throwable $e) {
            return 'drift: not measured ('.$this->why($e).')';
        }

        $psi = $report['input']['psi'] ?? null;

        if ($psi === null) {
            return 'drift: no category data in both periods';
        }

        return sprintf('drift: category PSI %.2f (%s)', $psi, $report['input']['verdict'] ?? 'stable');
    }

    private function scorecardLine(string $feature, string $metric, string $label, bool $percent): string
    {
        try {
            $quarter = $this->value($this->scorecard->compute($feature, 90, false), $metric);

            $month = $this->value($this->scorecard->compute($feature, 30, false), $metric);
        } catch (Throwable $e) {
            return "{$label}: not measured (".$this->why($e).')';
        }

        if ($quarter === null && $month === null) {
            return "{$label}: no data in the last 90 days";
        }

        return sprintf('%s: %s (90 days) → %s (30 days)', $label, $this->show($quarter, $percent), $this->show($month, $percent));
    }

    private function reconcileLine(): string
    {
        $last = Cache::get('ai.reconcile.last');

        if (! is_array($last)) {
            return 'reconcile: no hosted spend has been reconciled yet';
        }

        return sprintf('reconcile: %s gap %.1f%% on %s', $last['provider'], $last['gap_share'] * 100, $last['date']);
    }

    private function value(array $scorecard, string $metric): ?float
    {
        foreach ($scorecard['rows'] as $row) {
            if ($row['metric'] === $metric) {
                $value = $row['values']['control'] ?? null;

                return $value === null
                    ? null
                    : (float) $value;
            }
        }

        return null;
    }

    private function show(?float $value, bool $percent): string
    {
        if ($value === null) {
            return '—';
        }

        return $percent
            ? round($value * 100, 1).'%'
            : '$'.number_format($value, 4);
    }

    private function why(Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 80);
    }
}
