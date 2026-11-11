<?php

namespace App\Ai\Cost\Audit;

use App\Ai\Cost\Audit\Signatures\AnonymousSignature;
use App\Ai\Cost\Audit\Signatures\CacheSignature;
use App\Ai\Cost\Audit\Signatures\ContextSignature;
use App\Ai\Cost\Audit\Signatures\LoopSignature;
use App\Ai\Cost\Audit\Signatures\RetrySignature;
use App\Ai\Cost\CallCost;
use App\Ai\Cost\PriceMap;
use App\Ai\Cost\UnpricedModel;
use App\Models\AnswerSignal;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\TriageRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// Chapter 35, Topic 9: prices every call in the window from its logged usage, rolls the calls up into conversations, joins each to its outcome and reports cost per successful action, the toxic tail and the five signatures.
final class CostAudit
{
    public function __construct(private PriceMap $prices) {}

    /** @return Signature[] */
    public function signatures(): array
    {
        return [new LoopSignature, new ContextSignature, new CacheSignature, new RetrySignature, new AnonymousSignature];
    }

    /** @throws UnpricedModel when a call in the window names a model nobody priced */
    public function run(CarbonImmutable $since, ?string $feature = null): AuditReport
    {
        $data = $this->load($since, $feature);

        return $this->report($data);
    }

    public function load(CarbonImmutable $since, ?string $feature = null): AuditData
    {
        $calls = $this->calls($since, $feature);

        return new AuditData(
            since: $since,
            calls: $calls,
            units: $this->units($calls),
            staleAnswers: $this->staleAnswers($since),
            automatedClassifications: TriageRun::query()->where('status', 'automated')->where('created_at', '>=', $since)->count(),
        );
    }

    public function report(AuditData $data): AuditReport
    {
        $tail = $this->toxicTail($data->units);

        return new AuditReport(
            spend: $data->spend(),
            features: $this->perFeature($data),
            tailUnits: $tail['units'],
            tailSpendShare: $tail['spend_share'],
            tailResolvedShare: $tail['resolved_share'],
            signatures: array_map(fn (Signature $signature) => $signature->detect($data), $this->signatures()),
            data: $data,
        );
    }

    /** @return Collection<int, AuditCall> */
    private function calls(CarbonImmutable $since, ?string $feature): Collection
    {
        $query = ModelCall::query()->where('created_at', '>=', $since)->orderBy('id');

        if ($feature !== null) {
            $query->where('feature', $feature);
        }

        $unpriced = [];

        $calls = collect();

        foreach ($query->cursor() as $row) {
            $price = $this->prices->find($row->provider, $row->model);

            if ($price === null) {
                $unpriced["{$row->provider}/{$row->model}"] = true;

                continue;
            }

            $calls->push(new AuditCall(
                id: $row->id,
                feature: $row->feature,
                provider: $row->provider,
                model: $row->model,
                conversationId: $row->conversation_id,
                jobUuid: $row->job_uuid,
                attempt: $row->attempt,
                promptVersion: $row->prompt_version,
                inputTokens: (int) $row->prompt_tokens,
                cachedInputTokens: (int) $row->cached_input_tokens,
                outputTokens: (int) $row->completion_tokens,
                usd: CallCost::usd($row, $price),
                traceId: $row->trace_id,
                at: CarbonImmutable::parse($row->created_at),
            ));
        }

        if ($unpriced !== []) {
            throw new UnpricedModel('No price for '.count($unpriced).' model(s) in the window: '.implode(', ', array_keys($unpriced)).'. Add them to config/ai-pricing.php; an unpriced model is never counted as free.');
        }

        return $calls;
    }

    /** @return Collection<int, Unit> */
    private function units(Collection $calls): Collection
    {
        $conversationIds = $calls->pluck('conversationId')->filter()->unique()->values();

        $tenants = Conversation::withoutGlobalScopes()->whereIn('id', $conversationIds)->pluck('tenant_id', 'id');

        $resolved = AnswerSignal::query()->whereIn('conversation_id', $conversationIds)->where('resolved', true)->pluck('conversation_id')->flip();

        return $calls->groupBy(fn (AuditCall $call) => $call->unit())->map(function (Collection $group, string $key) use ($tenants, $resolved) {
            $conversationId = $group->first()->conversationId;

            return new Unit(
                key: $key,
                calls: $group->values(),
                conversationId: $conversationId,
                tenantId: $conversationId === null
                    ? null
                    : ($tenants[$conversationId] ?? null),
                resolved: $conversationId !== null && $resolved->has($conversationId),
            );
        })->values();
    }

    // A model answer written long after the question was asked: the customer had gone (the freshness check on AnswerQuestion answers those from a template).
    private function staleAnswers(CarbonImmutable $since): int
    {
        $minutes = (int) config('ai.replies.freshness_minutes', 30);

        return Message::withoutGlobalScopes()
            ->where('role', 'assistant')
            ->whereNotNull('question')
            ->where('status', 'completed')
            ->whereNotNull('model_call_id')
            ->where('created_at', '>=', $since)
            ->whereRaw("updated_at - created_at > make_interval(mins => {$minutes})")
            ->count();
    }

    /** @return array<string, array> */
    private function perFeature(AuditData $data): array
    {
        $features = [];

        foreach ($data->calls->groupBy('feature') as $feature => $calls) {
            if (! in_array($feature, ['support.reply', 'ticket.classify'], true)) {
                continue;
            }

            $features[$feature] = $this->featureRow($data, $feature);
        }

        return $features;
    }

    private function featureRow(AuditData $data, string $feature): array
    {
        if ($feature === 'ticket.classify') {
            $spend = $data->calls->where('feature', $feature)->sum('usd');

            return $this->row($spend, $data->automatedClassifications, $data->automatedClassifications, 'agreed classification');
        }

        $units = $data->units->filter(fn (Unit $unit) => $unit->conversationId !== null && $unit->feature() === $feature);

        return $this->row($units->sum(fn (Unit $unit) => $unit->usd()), $units->where('resolved', true)->count(), $units->count(), 'resolution');
    }

    private function row(float $spend, int $successes, int $actions, string $noun): array
    {
        return [
            'spend' => $spend,
            'successes' => $successes,
            'actions' => $actions,
            'per_success' => $successes > 0
                ? $spend / $successes
                : null,
            'noun' => $noun,
        ];
    }

    // The top 1% of conversations by cost: what share of the spend they hold, and how many of them ended well.
    private function toxicTail(Collection $units): array
    {
        $conversations = $units->filter(fn (Unit $unit) => $unit->conversationId !== null)->sortByDesc(fn (Unit $unit) => $unit->usd())->values();

        if ($conversations->isEmpty()) {
            return ['units' => 0, 'spend_share' => 0.0, 'resolved_share' => 0.0];
        }

        $take = max(1, (int) ceil($conversations->count() * (float) config('ai.cost.audit.toxic_tail_share', 0.01)));

        $tail = $conversations->take($take);

        $total = $conversations->sum(fn (Unit $unit) => $unit->usd());

        return [
            'units' => $take,
            'spend_share' => $total > 0
                ? $tail->sum(fn (Unit $unit) => $unit->usd()) / $total
                : 0.0,
            'resolved_share' => $tail->where('resolved', true)->count() / $take,
        ];
    }
}
