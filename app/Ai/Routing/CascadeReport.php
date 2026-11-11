<?php

namespace App\Ai\Routing;

use App\Models\ModelCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// Chapter 37, Topic 9: where each category settles, why escalations happened, and what the answers cost against sending everything to the most capable role. Read from model_calls, where every attempt of a cascade carries the run it belongs to,
// its step and the checks it failed.
final class CascadeReport
{
    /**
     * @return array{answers: int, categories: array<string, array>, cost_per_answer: float, baseline_per_answer: float, cheapest_share: float, handed_off_share: float, extra_latency_ms: ?int, escalated_extra_ms: array<string, ?int>}
     */
    public function between(CarbonInterface $from, CarbonInterface $to, string $feature = 'support.reply'): array
    {
        $answers = $this->answers($from, $to, $feature);

        $cheapest = $this->roleOf((string) (config('ai.cascade.roles')[0] ?? 'local'));

        $categories = [];

        foreach ($answers->groupBy(fn (array $answer) => $answer['category']) as $category => $group) {
            $categories[$category] = $this->category($group);
        }

        ksort($categories);

        $count = $answers->count();

        $cost = $answers->sum('cost');

        $frontier = $answers->filter(fn (array $answer) => $answer['settled_role'] === 'frontier' && ! $answer['handed_off']);

        $baseline = $frontier->count() > 0
            ? $frontier->sum('cost') / $frontier->count()
            : (float) (config('ai.cascade.cost_per_answer_usd')['frontier'] ?? 0);

        return [
            'answers' => $count,
            'categories' => $categories,
            'cost_per_answer' => $count > 0 ? $cost / $count : 0.0,
            'baseline_per_answer' => $baseline,
            'cheapest_share' => $count > 0
                ? $answers->filter(fn (array $answer) => ! $answer['handed_off'] && $answer['settled_role'] === $cheapest)->count() / $count
                : 0.0,
            'handed_off_share' => $count > 0
                ? $answers->where('handed_off', true)->count() / $count
                : 0.0,
            'extra_latency_ms' => $this->median($answers->where('attempts', '>', 1)->pluck('extra_ms')),
            'escalated_extra_ms' => collect($categories)->map(fn (array $row) => $row['extra_ms'])->all(),
        ];
    }

    // One row per question: the attempts of a cascade, cheapest first, with what each cost and why the ones that failed failed.
    public function conversation(int $conversationId): Collection
    {
        return $this->answerRows(ModelCall::query()->where('conversation_id', $conversationId)->whereNotNull('cascade_id')->orderBy('id')->get());
    }

    /** @return Collection<int, array> */
    private function answers(CarbonInterface $from, CarbonInterface $to, string $feature): Collection
    {
        return $this->answerRows(ModelCall::query()->where('feature', $feature)->whereNotNull('cascade_id')->where('created_at', '>=', $from)->where('created_at', '<=', $to)->orderBy('id')->get());
    }

    /** @return Collection<int, array> */
    private function answerRows(Collection $calls): Collection
    {
        return $calls->groupBy('cascade_id')->map(function (Collection $rows) {
            $steps = $rows->groupBy('cascade_step')->sortKeys();

            $attempts = $steps->map(function (Collection $step) {
                $answering = $step->reject(fn (ModelCall $call) => $call->provider === 'degraded')->last() ?? $step->last();

                return [
                    'step' => (int) $answering->cascade_step,
                    'provider' => $answering->provider,
                    'role' => $this->roleOf($answering->provider),
                    'failed' => $step->pluck('cascade_failed')->filter()->first(),
                    'cost' => $step->sum('cost_micros') / 1_000_000,
                    'latency_ms' => (int) $step->sum('latency_ms'),
                ];
            })->values();

            $last = $attempts->last();

            return [
                'id' => $rows->first()->cascade_id,
                'category' => (string) ($rows->first()->category ?? 'unknown'),
                'attempts' => $attempts->count(),
                'start_role' => $attempts->first()['role'],
                'settled_role' => $last['role'],
                'handed_off' => $last['failed'] !== null,
                'reasons' => $attempts->pluck('failed')->filter()->flatMap(fn (string $failed) => explode(',', $failed))->values()->all(),
                'cost' => $attempts->sum('cost'),
                'extra_ms' => (int) $attempts->slice(0, -1)->sum('latency_ms'),
                'steps' => $attempts->all(),
            ];
        })->values();
    }

    private function category(Collection $answers): array
    {
        $count = $answers->count();

        $reasons = $answers->flatMap(fn (array $answer) => $answer['reasons'])->countBy()->sortDesc();

        $settled = $answers->reject(fn (array $answer) => $answer['handed_off'])->countBy('settled_role');

        return [
            'answers' => $count,
            'start' => (string) $answers->pluck('start_role')->countBy()->sortDesc()->keys()->first(),
            'settled' => $settled->map(fn (int $n) => $n / $count)->all(),
            'handed_off' => $answers->where('handed_off', true)->count() / $count,
            'top_reason' => $reasons->keys()->first(),
            'extra_ms' => $this->median($answers->where('attempts', '>', 1)->pluck('extra_ms')),
            'cost' => $answers->sum('cost') / $count,
        ];
    }

    private function median(Collection $values): ?int
    {
        $sorted = $values->sort()->values();

        return $sorted->isEmpty()
            ? null
            : (int) $sorted[(int) floor(($sorted->count() - 1) / 2)];
    }

    // The role a provider name stands for in the table: local, mid or frontier by the tiers, else its own name.
    private function roleOf(string $provider): string
    {
        foreach (['local' => 'small', 'mid' => 'mid', 'frontier' => 'frontier'] as $role => $tier) {
            if ((string) config("ai.tiers.{$tier}") === $provider) {
                return $role;
            }
        }

        return $provider;
    }
}
