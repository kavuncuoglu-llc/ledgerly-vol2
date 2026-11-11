<?php

namespace App\Evals;

use Illuminate\Support\Collection;
use InvalidArgumentException;

// Chapter 29, Topic 5 and the Build: reads promptfoo's results.json and applies the two thresholds, the same ones eval:gates applies in PHP. A quality failure counts against the pass rate; a never-gate failure counts against zero.
final class ResultsChecker
{
    public function check(array $results, float $qualityThreshold, int $neverAllowed): array
    {
        $rows = collect($results['results']['results'] ?? $results['results'] ?? null);

        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('That file has no promptfoo results (expected results.results[]). Was it written with -o results.json?');
        }

        $checked = $rows->map(fn (array $row) => $this->row($row))->values();

        $total = $checked->count();
        $qualityPasses = $checked->where('quality', true)->count();
        $neverFailures = $checked->where('never', false)->count();
        $judged = $checked->whereNotNull('judge');

        return [
            'cases' => $total,
            'quality_passes' => $qualityPasses,
            'quality_pass_rate' => round($qualityPasses / $total, 4),
            'quality_threshold' => $qualityThreshold,
            'never_failures' => $neverFailures,
            'never_allowed' => $neverAllowed,
            'quality_ok' => $qualityPasses / $total >= $qualityThreshold,
            'never_ok' => $neverFailures <= $neverAllowed,
            'passed' => $qualityPasses / $total >= $qualityThreshold && $neverFailures <= $neverAllowed,
            'judge_cases' => $judged->count(),
            'judge_pass_rate' => $judged->isEmpty() ? null : round($judged->where('judge', true)->count() / $judged->count(), 4),
            'by_source' => $this->bySource($checked),
            'failing' => $checked->reject(fn (array $row) => $row['quality'] && $row['never'])->map(fn (array $row) => ['id' => $row['id'], 'reason' => $row['reason']])->values()->all(),
        ];
    }

    private function row(array $row): array
    {
        $grading = $row['gradingResult'] ?? null;

        $named = $grading['namedScores'] ?? $row['namedScores'] ?? null;

        $reason = (string) ($grading['reason'] ?? $row['error'] ?? 'no result');

        $providerFailed = $grading === null
            || ($row['response']['output'] ?? null) === null;

        $quality = $providerFailed
            ? false
            : (isset($named['quality']) ? (float) $named['quality'] >= 1 : (bool) ($row['success'] ?? false));

        $never = isset($named['never'])
            ? (float) $named['never'] >= 1
            : ! str_contains($reason, 'NEVER:');

        return [
            'id' => (string) ($row['testCase']['description'] ?? $row['vars']['case_id'] ?? $row['testIdx'] ?? '?'),
            'source' => (string) ($row['testCase']['metadata']['source'] ?? $row['vars']['source'] ?? 'unknown'),
            'quality' => $quality,
            'never' => $never,
            'judge' => $this->judge($grading),
            'reason' => $reason,
        ];
    }

    private function judge(?array $grading): ?bool
    {
        $rubrics = collect($grading['componentResults'] ?? [])
            ->filter(fn (array $component) => ($component['assertion']['type'] ?? '') === 'llm-rubric');

        if ($rubrics->isEmpty()) {
            return null;
        }

        return $rubrics->every(fn (array $component) => (bool) ($component['pass'] ?? false));
    }

    private function bySource(Collection $checked): array
    {
        return $checked
            ->groupBy('source')
            ->map(fn (Collection $group) => [
                'cases' => $group->count(),
                'quality_pass_rate' => round($group->where('quality', true)->count() / $group->count(), 4),
            ])
            ->all();
    }
}
