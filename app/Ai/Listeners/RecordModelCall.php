<?php

namespace App\Ai\Listeners;

use App\Ai\Events\ModelCalled;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Log;

final class RecordModelCall
{
    public function handle(ModelCalled $e): void
    {
        $rates = $this->ratesFor($e->provider, $e->model); // $/1M tokens

        $promptTokens = $e->usage['prompt_tokens'] ?? 0;
        $completionTokens = $e->usage['completion_tokens'] ?? 0;

        $row = ModelCall::create([
            'feature' => $e->feature,
            'provider' => $e->provider,
            'model' => $e->model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'finish_reason' => $e->finishReason,
            'latency_ms' => $e->latencyMs,
            'cost_micros' => (int) round($promptTokens * $rates['in'] + $completionTokens * $rates['out']),
            'conversation_id' => $e->conversationId,
            'prompt_version' => $e->promptVersion,
            'repaired' => $e->repaired,
            'cached_tokens' => $e->cachedTokens ?? ($e->usage['cached_tokens'] ?? null),
            'ttft_ms' => $e->ttftMs,
            'context_breakdown' => $e->contextBreakdown,
            'semantic_cache_hit' => $e->semanticCacheHit,
            'request' => $e->request,
            'variant' => $e->variant,
        ]);

        $e->modelCallId = $row->id;

        if ($e->finishReason === 'length') {
            Log::warning('Completion truncated', ['feature' => $e->feature, 'usage' => $e->usage]);
        }
    }

    private function ratesFor(string $provider, string $model): array
    {
        // Model names contain dots ("llama3.1:8b"), so look the model up as an array key, not a config path.
        $providerRates = config("ai.rates.{$provider}", []);

        return $providerRates[$model] ?? $providerRates['*'] ?? ['in' => 0, 'out' => 0];
    }
}
