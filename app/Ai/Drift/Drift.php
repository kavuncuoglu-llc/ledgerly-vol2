<?php

namespace App\Ai\Drift;

use App\Evals\EvalBehavior;
use App\Models\AnswerFeedback;
use App\Models\Message;
use App\Models\OnlineJudgment;
use App\Models\ToolCall;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// Chapter 31, Topic 8: the world moving under a pinned system, measured from the audit trail. Input drift is a change in the mix of questions (population stability index over the router's categories); output drift is a change in how the
// assistant behaves (rolling z-score of a window against the daily values of a baseline); quality drift is the sampled judge and the thumbs. An alert is the start of an investigation, not a fix.
final class Drift
{
    public const PSI_LOOK = 0.10;

    public const PSI_ACT = 0.25;

    public const Z_ALERT = 3.0;

    public function __construct(private CurrentTenant $tenants) {}

    public function report(string $feature, int $windowDays, int $baselineDays): array
    {
        $now = CarbonImmutable::now();

        $windowStart = $now->subDays($windowDays);

        $baselineStart = $windowStart->subDays($baselineDays);

        return $this->tenants->run(null, function () use ($feature, $now, $windowStart, $baselineStart) {
            $window = $this->replies($feature, $windowStart, $now);

            $baseline = $this->replies($feature, $baselineStart, $windowStart);

            return [
                'feature' => $feature,
                'replies' => ['window' => $window->count(), 'baseline' => $baseline->count()],
                'input' => $this->input($window, $baseline),
                'output' => $this->output($window, $baseline, $windowStart, $baselineStart),
                'quality' => $this->quality($window, $baseline, $windowStart, $baselineStart, $now),
            ];
        });
    }

    // sum over categories of (current share − baseline share) × ln(current share ÷ baseline share); a category absent from one side counts as a tiny share, not zero
    public static function psi(array $current, array $baseline): float
    {
        $epsilon = 0.0001;

        $psi = 0.0;

        foreach (array_unique([...array_keys($current), ...array_keys($baseline)]) as $category) {
            $now = max($epsilon, $current[$category] ?? 0);

            $then = max($epsilon, $baseline[$category] ?? 0);

            $psi += ($now - $then) * log($now / $then);
        }

        return round($psi, 4);
    }

    public static function zScore(float $value, array $history): float
    {
        if (count($history) < 2) {
            return 0.0;
        }

        $mean = array_sum($history) / count($history);

        $variance = array_sum(array_map(fn (float $x) => ($x - $mean) ** 2, $history)) / (count($history) - 1);

        return $variance > 0
            ? round(($value - $mean) / sqrt($variance), 2)
            : 0.0;
    }

    public static function verdict(float $psi): string
    {
        return match (true) {
            $psi > self::PSI_ACT => 'ACT',
            $psi >= self::PSI_LOOK => 'look',
            default => 'stable',
        };
    }

    /** @return Collection<int, Message> */
    private function replies(string $feature, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->where('role', 'assistant')
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$from, $to])
            ->get();
    }

    private function input(Collection $window, Collection $baseline): array
    {
        $shares = fn (Collection $replies) => $replies->whereNotNull('category')->countBy('category')->map(fn (int $n) => $n / max(1, $replies->whereNotNull('category')->count()))->all();

        $current = $shares($window);

        $before = $shares($baseline);

        $moved = collect(array_unique([...array_keys($current), ...array_keys($before)]))
            ->map(fn (string $category) => ['category' => $category, 'baseline' => $before[$category] ?? 0.0, 'current' => $current[$category] ?? 0.0])
            ->sortByDesc(fn (array $row) => abs($row['current'] - $row['baseline']))
            ->values()
            ->all();

        $psi = $current === [] || $before === []
            ? null
            : self::psi($current, $before);

        return ['psi' => $psi, 'verdict' => $psi === null ? 'no data' : self::verdict($psi), 'moved' => $moved];
    }

    private function output(Collection $window, Collection $baseline, CarbonImmutable $windowStart, CarbonImmutable $baselineStart): array
    {
        $metrics = [
            'decline rate' => fn (Collection $replies) => $this->share($replies, fn (Message $m) => EvalBehavior::classify((string) $m->content) === 'decline'),
            'citations/answer' => fn (Collection $replies) => $replies->isEmpty() ? null : $replies->sum(fn (Message $m) => preg_match_all('/\[[^\]]+\]/u', (string) $m->content)) / $replies->count(),
            'answer words' => fn (Collection $replies) => $replies->isEmpty() ? null : $replies->avg(fn (Message $m) => str_word_count((string) $m->content)),
            'escalation rate' => fn (Collection $replies) => $this->share($replies, fn (Message $m) => ToolCall::query()->where('conversation_id', $m->conversation_id)->where('tool', 'open_ticket')->whereIn('decision', ['allowed', 'approved'])->exists()),
        ];

        return $this->compare($metrics, $window, $baseline, $baselineStart, $windowStart);
    }

    private function quality(Collection $window, Collection $baseline, CarbonImmutable $windowStart, CarbonImmutable $baselineStart, CarbonImmutable $now): array
    {
        $faithfulness = fn (CarbonImmutable $from, CarbonImmutable $to) => OnlineJudgment::query()->where('metric', 'faithfulness')->whereBetween('created_at', [$from, $to])->get();

        $current = $faithfulness($windowStart, $now);

        $history = $faithfulness($baselineStart, $windowStart)->groupBy(fn ($row) => $row->created_at->toDateString())->map(fn (Collection $day) => (float) $day->avg('score'))->values()->all();

        $out = [];

        if ($current->isNotEmpty() && $history !== []) {
            $mean = (float) $current->avg('score');

            $out['sampled faithfulness'] = ['baseline' => round(array_sum($history) / count($history), 4), 'current' => round($mean, 4), 'z' => self::zScore($mean, $history)];
        }

        $down = fn (CarbonImmutable $from, CarbonImmutable $to) => AnswerFeedback::withoutGlobalScopes()->whereBetween('created_at', [$from, $to])->get();

        $ratedNow = $down($windowStart, $now);

        $ratedThen = $down($baselineStart, $windowStart);

        if ($ratedNow->isNotEmpty() && $ratedThen->isNotEmpty()) {
            $daily = $ratedThen->groupBy(fn ($row) => $row->created_at->toDateString())->map(fn (Collection $day) => $day->where('rating', 'down')->count() / $day->count())->values()->all();

            $rate = $ratedNow->where('rating', 'down')->count() / $ratedNow->count();

            $out['thumbs-down'] = ['baseline' => round($ratedThen->where('rating', 'down')->count() / $ratedThen->count(), 4), 'current' => round($rate, 4), 'z' => self::zScore($rate, $daily)];
        }

        return $out;
    }

    private function compare(array $metrics, Collection $window, Collection $baseline, CarbonImmutable $baselineStart, CarbonImmutable $windowStart): array
    {
        $out = [];

        foreach ($metrics as $name => $measure) {
            $current = $measure($window);

            $perDay = $baseline->groupBy(fn (Message $m) => $m->updated_at->toDateString())->map(fn (Collection $day) => $measure($day))->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values()->all();

            if ($current === null || $perDay === []) {
                continue;
            }

            $out[$name] = ['baseline' => round(array_sum($perDay) / count($perDay), 4), 'current' => round((float) $current, 4), 'z' => self::zScore((float) $current, $perDay)];
        }

        return $out;
    }

    private function share(Collection $replies, callable $test): ?float
    {
        return $replies->isEmpty()
            ? null
            : $replies->filter($test)->count() / $replies->count();
    }
}
