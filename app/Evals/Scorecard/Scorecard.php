<?php

namespace App\Evals\Scorecard;

use App\Evals\Gates;
use App\Models\AnswerFeedback;
use App\Models\AnswerSignal;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\OnlineJudgment;
use App\Models\ShadowAnswerRecord;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// Chapter 30, Topic 1: a feature's metrics for a window, split by variant, each against the target and floor in config('ai.scorecards'). A metric the logs cannot supply yet is null and shows as a dash.
final class Scorecard
{
    public function __construct(private CurrentTenant $tenants) {}

    /** @return array{feature: string, from: string, to: string, columns: list<array{key: string, label: string, shadow: bool}>, rows: list<array>, feedback: array, stop: bool} */
    public function compute(string $feature, int $days, bool $byVariant): array
    {
        $to = CarbonImmutable::now();

        $from = $to->subDays($days);

        return $this->tenants->run(null, function () use ($feature, $byVariant, $from, $to) {
            $columns = $this->columns($feature, $from, $byVariant);

            $values = [];

            foreach ($columns as $column) {
                $values[$column['key']] = $this->metrics($feature, $column, $from, $to);
            }

            $rows = $this->rows($feature, $columns, $values);

            return [
                'feature' => $feature,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'columns' => $columns,
                'rows' => $rows,
                'feedback' => $this->feedback($from),
                'stop' => collect($rows)->contains(fn (array $row) => collect($row['flags'])->contains('STOP')),
            ];
        });
    }

    /** @return list<array{key: string, label: string, shadow: bool}> */
    private function columns(string $feature, CarbonImmutable $from, bool $byVariant): array
    {
        $columns = [['key' => 'control', 'label' => 'control', 'shadow' => false]];

        if (! $byVariant) {
            return $columns;
        }

        $treatment = Message::withoutGlobalScopes()->where('experiment_variant', 'treatment')->where('updated_at', '>=', $from)->exists();

        if ($treatment) {
            $columns[] = ['key' => 'treatment', 'label' => 'treatment', 'shadow' => false];
        }

        if ($feature !== 'support.reply') {
            return $columns;
        }

        foreach (ShadowAnswerRecord::query()->where('created_at', '>=', $from)->distinct()->pluck('variant') as $variant) {
            $columns[] = ['key' => "shadow:{$variant}", 'label' => "shadow ({$variant})", 'shadow' => true];
        }

        return $columns;
    }

    private function metrics(string $feature, array $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($feature !== 'support.reply') {
            return $this->genericMetrics($feature, $from, $to);
        }

        return $column['shadow']
            ? $this->shadowMetrics(substr($column['key'], 7), $from)
            : $this->liveMetrics($column['key'], $from, $to);
    }

    private function liveMetrics(string $variant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $replies = Message::withoutGlobalScopes()
            ->whereNotNull('question')
            ->where('role', 'assistant')
            ->where('status', 'completed')
            ->where('updated_at', '>=', $from)
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('experiment_variant')->orWhere('experiment_variant', 'control')), fn ($q) => $q->where('experiment_variant', $variant))
            ->with('conversation')
            ->get();

        $gated = $replies->map(function (Message $reply) {
            $customerId = User::query()->whereKey($reply->conversation->user_id)->value('customer_id');

            return Gates::evaluate((string) $reply->content, $reply->retrievedLabels(), customerId: $customerId);
        });

        $signals = AnswerSignal::query()
            ->where('computed_on', '>=', $to->subDays(7)->toDateString())
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('variant')->orWhere('variant', 'control')), fn ($q) => $q->where('variant', $variant))
            ->get();

        return [
            'resolved_without_human_7d' => $signals->isEmpty() ? null : $signals->where('resolved', true)->count() / $signals->count(),
            'golden_quality_pass' => $this->share($gated, fn ($report) => $report->passedQuality()),
            'judge_faithfulness_sample' => $this->judged($variant, 'faithfulness', $from),
            'citation_validity_live' => $this->share($gated, fn ($report) => collect($report->results)->firstWhere('gate', 'citations')->passed),
            'never_gate_failures' => $replies->isEmpty() ? null : $gated->reject(fn ($report) => $report->passedNever())->count(),
            'disputes_escalated' => null,
            'cost_per_answer_usd' => $this->costPerCall('support.reply', $variant, $from),
            'cached_input_share' => $this->cachedInputShare('support.reply', $variant, $from),
            'ttft_p95_seconds' => $this->ttftP95($variant, $from),
        ];
    }

    private function shadowMetrics(string $variant, CarbonImmutable $from): array
    {
        $records = ShadowAnswerRecord::query()->where('variant', $variant)->where('created_at', '>=', $from)->get();

        $passing = fn (string $gate) => $records->isEmpty() ? null : $records->filter(fn ($record) => collect($record->gates)->firstWhere('gate', $gate)['passed'] ?? true)->count() / $records->count();

        return [
            'resolved_without_human_7d' => null,                                       // customers never see a shadow answer, so there is no behaviour to measure
            'golden_quality_pass' => $records->isEmpty() ? null : $records->filter(fn ($record) => collect($record->gates)->where('kind', 'quality')->every(fn ($gate) => $gate['passed']))->count() / $records->count(),
            'judge_faithfulness_sample' => $this->judged($variant, 'faithfulness', $from),
            'citation_validity_live' => $passing('citations'),
            'never_gate_failures' => $records->isEmpty() ? null : $records->filter(fn ($record) => collect($record->gates)->where('kind', 'never')->contains(fn ($gate) => ! $gate['passed']))->count(),
            'disputes_escalated' => null,
            'cost_per_answer_usd' => $this->costPerCall('support.shadow', $variant, $from),
            'cached_input_share' => null,
            'ttft_p95_seconds' => null,
        ];
    }

    private function genericMetrics(string $feature, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calls = ModelCall::query()->where('feature', $feature)->where('created_at', '>=', $from)->get();

        $cost = $calls->isEmpty() ? null : $calls->sum('cost_micros') / 1_000_000 / $calls->count();

        return [
            'agreement_with_agent_label' => null,                                      // needs the label a support agent assigned; the logs do not have it yet
            'cost_per_ticket_usd' => $cost,
        ];
    }

    private function share(Collection $reports, callable $passes): ?float
    {
        return $reports->isEmpty()
            ? null
            : $reports->filter($passes)->count() / $reports->count();
    }

    private function judged(string $variant, string $metric, CarbonImmutable $from): ?float
    {
        $scores = OnlineJudgment::query()
            ->where('metric', $metric)
            ->where('created_at', '>=', $from)
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('variant')->orWhere('variant', 'control')), fn ($q) => $q->where('variant', $variant))
            ->pluck('score');

        return $scores->isEmpty()
            ? null
            : round($scores->avg(), 4);
    }

    private function costPerCall(string $feature, string $variant, CarbonImmutable $from): ?float
    {
        $calls = ModelCall::query()
            ->where('feature', $feature)
            ->where('created_at', '>=', $from)
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('variant')->orWhere('variant', 'control')), fn ($q) => $q->where('variant', $variant))
            ->get(['cost_micros']);

        return $calls->isEmpty()
            ? null
            : $calls->sum('cost_micros') / 1_000_000 / $calls->count();
    }

    // Chapter 35, Topic 5: the share of input tokens served from the prompt cache. A prefix that stopped caching shows up here first.
    private function cachedInputShare(string $feature, string $variant, CarbonImmutable $from): ?float
    {
        $calls = ModelCall::query()
            ->where('feature', $feature)
            ->where('created_at', '>=', $from)
            ->where('prompt_tokens', '>', 0)
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('variant')->orWhere('variant', 'control')), fn ($q) => $q->where('variant', $variant))
            ->get(['prompt_tokens', 'cached_input_tokens']);

        return $calls->isEmpty()
            ? null
            : $calls->sum('cached_input_tokens') / $calls->sum('prompt_tokens');
    }

    private function ttftP95(string $variant, CarbonImmutable $from): ?float
    {
        $values = ModelCall::query()
            ->where('feature', 'support.reply')
            ->whereNotNull('ttft_ms')
            ->where('created_at', '>=', $from)
            ->when($variant === 'control', fn ($q) => $q->where(fn ($q) => $q->whereNull('variant')->orWhere('variant', 'control')), fn ($q) => $q->where('variant', $variant))
            ->pluck('ttft_ms')
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values[(int) max(0, ceil(0.95 * $values->count()) - 1)] / 1000;
    }

    private function feedback(CarbonImmutable $from): array
    {
        $rated = AnswerFeedback::withoutGlobalScopes()->where('created_at', '>=', $from)->get();

        $conversations = Message::withoutGlobalScopes()->whereNotNull('question')->where('updated_at', '>=', $from)->distinct('conversation_id')->count('conversation_id');

        $ratedConversations = Message::withoutGlobalScopes()->whereIn('id', $rated->pluck('message_id'))->distinct('conversation_id')->count('conversation_id');

        return ['rated' => $ratedConversations, 'conversations' => $conversations, 'down' => $rated->where('rating', 'down')->count()];
    }

    /** @return list<array{metric: string, kind: string, floor: float|int, direction: string, values: array<string, float|int|null>, flags: array<string, string>}> */
    private function rows(string $feature, array $columns, array $values): array
    {
        $rows = [];

        foreach ((array) (config('ai.scorecards')[$feature] ?? []) as $metric => $spec) {       // feature names contain dots: never a dotted config path
            $direction = $spec['direction'] ?? 'up';

            $flags = [];

            foreach ($columns as $column) {
                $value = $values[$column['key']][$metric] ?? null;

                if ($value === null) {
                    continue;
                }

                $below = $direction === 'down'
                    ? $value > $spec['floor']
                    : $value < $spec['floor'];

                if ($below) {
                    $flags[$column['key']] = $metric === 'never_gate_failures' ? 'STOP' : 'below floor';
                }
            }

            $rows[] = [
                'metric' => $metric,
                'kind' => $spec['kind'],
                'floor' => $spec['floor'],
                'direction' => $direction,
                'values' => array_combine(array_column($columns, 'key'), array_map(fn (array $column) => $values[$column['key']][$metric] ?? null, $columns)),
                'flags' => $flags,
            ];
        }

        return $rows;
    }
}
