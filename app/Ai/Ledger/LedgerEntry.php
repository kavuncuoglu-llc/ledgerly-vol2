<?php

namespace App\Ai\Ledger;

use App\Ai\Budgets\Teams;
use App\Ai\Events\ModelCalled;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Context;

// Chapter 36, Topic 3: one ledger row, with its labels read at write time and never guessed later. The book's listing builds it from the request and the response; the repository builds it from the ModelCalled event
// RecordModelCall has just stored, which carries the same facts (feature, the provider and model that answered after routing and fallback, the usage) and the ids that join the row to model_calls and its trace.
final class LedgerEntry
{
    /** @return array<string, mixed> the columns of ai_ledger (without entry_id, which the stream gives) */
    public static function fromEvent(ModelCalled $e, Costing $costing): array
    {
        $cost = $costing->of(
            $e->provider,
            $e->model,
            (int) ($e->usage['prompt_tokens'] ?? 0),
            (int) ($e->usage['completion_tokens'] ?? 0),
            (int) ($e->cachedTokens ?? 0),
            (int) ($e->cacheWriteTokens ?? $e->usage['cache_write_tokens'] ?? 0),
        );

        return [
            'occurred_at' => now()->toIso8601String(),
            'model_call_id' => $e->modelCallId,
            'trace_id' => $e->traceId,
            'tenant_id' => self::tenantId(),
            'user_id' => Context::get('user_id'),
            'feature' => $e->feature === '' ? 'unattributed' : $e->feature,
            'team' => Teams::of($e->feature),
            'provider' => $e->provider,
            'model' => $e->model,
            'input_tokens' => (int) ($e->usage['prompt_tokens'] ?? 0),
            'cached_input_tokens' => (int) ($e->cachedTokens ?? 0),
            'cache_write_tokens' => (int) ($e->cacheWriteTokens ?? $e->usage['cache_write_tokens'] ?? 0),
            'output_tokens' => (int) ($e->usage['completion_tokens'] ?? 0),
            'price_version' => $costing->version(),
            'cost_micro_usd' => $cost['micros'],
            'kind' => $cost['kind'],
            'reason' => $cost['reason'],
            'cascade_step' => $e->cascade['step'] ?? null,
        ];
    }

    // Whose work it is: the request's tenant (set by AttributeAiSpend and carried into jobs by Context), else the tenant the code is running as. Null is anonymous traffic, or work nobody asked for (a command).
    public static function tenantId(): ?int
    {
        $tenant = Context::get('tenant_id');

        if ($tenant !== null) {
            return (int) $tenant;
        }

        $resolved = app(CurrentTenant::class)->resolve();

        return is_int($resolved)
            ? $resolved
            : null;
    }
}
