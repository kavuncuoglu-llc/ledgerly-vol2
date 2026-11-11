<?php

namespace App\Ai\Listeners;

use App\Ai\Cost\CallCost;
use App\Ai\Cost\PriceMap;
use App\Ai\Events\ModelCalled;
use App\Ai\Ledger\LedgerWriter;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

final class RecordModelCall
{
    public function handle(ModelCalled $e): void
    {
        $promptTokens = $e->usage['prompt_tokens'] ?? 0;
        $completionTokens = $e->usage['completion_tokens'] ?? 0;

        $cachedTokens = $e->cachedTokens ?? ($e->usage['cached_tokens'] ?? null);
        $cacheWriteTokens = $e->cacheWriteTokens ?? ($e->usage['cache_write_tokens'] ?? 0);

        $row = ModelCall::create([
            'feature' => $e->feature,
            'provider' => $e->provider,
            'model' => $e->model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'finish_reason' => $e->finishReason,
            'latency_ms' => $e->latencyMs,
            'cost_micros' => $this->costMicros($e, $promptTokens, $completionTokens, (int) $cachedTokens, (int) $cacheWriteTokens),
            'conversation_id' => $e->conversationId,
            'prompt_version' => $e->promptVersion,
            'repaired' => $e->repaired,
            'cached_input_tokens' => $cachedTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'job_uuid' => Context::get('job_uuid'),
            'attempt' => Context::get('job_attempt'),
            'ttft_ms' => $e->ttftMs,
            'context_breakdown' => $e->contextBreakdown,
            'semantic_cache_hit' => $e->semanticCacheHit,
            'request' => $e->request,
            'variant' => $e->variant,
            'trace_id' => $e->traceId,
            'span_id' => $e->spanId,
            'cascade_id' => $e->cascade['id'] ?? null,
            'cascade_step' => $e->cascade['step'] ?? null,
            'category' => $e->cascade['category'] ?? null,
        ]);

        $e->modelCallId = $row->id;

        $e->costMicros = $row->cost_micros;

        app(LedgerWriter::class)->append($e);                                        // Chapter 36: one ledger entry per call, on a Redis stream

        if ($e->finishReason === 'length') {
            Log::warning('Completion truncated', ['feature' => $e->feature, 'usage' => $e->usage]);
        }
    }

    // Chapter 35: a model the price map prices (config/ai-pricing.php) is costed from every side of its usage, cached reads and cache writes included; the older per-provider table is the fallback for one it does not.
    private function costMicros(ModelCalled $e, int $promptTokens, int $completionTokens, int $cachedTokens, int $cacheWriteTokens): int
    {
        $price = app(PriceMap::class)->find($e->provider, $e->model);

        if ($price !== null && ($price['input'] > 0 || $price['output'] > 0)) {
            return (int) round(CallCost::usdOf($promptTokens, $completionTokens, $price, $cachedTokens, $cacheWriteTokens) * 1_000_000);
        }

        $rates = $this->ratesFor($e->provider, $e->model); // $/1M tokens

        return (int) round($promptTokens * $rates['in'] + $completionTokens * $rates['out']);
    }

    private function ratesFor(string $provider, string $model): array
    {
        // Model names contain dots ("llama3.1:8b"), so look the model up as an array key, not a config path.
        $providerRates = config("ai.rates.{$provider}", []);

        return $providerRates[$model] ?? $providerRates['*'] ?? ['in' => 0, 'out' => 0];
    }
}
