<?php

namespace App\Ai\Cost\Audit\Signatures;

use App\Ai\Cost\Audit\AuditCall;
use App\Ai\Cost\Audit\AuditData;
use App\Ai\Cost\Audit\Signature;
use App\Ai\Cost\Audit\SignatureResult;

// "Fifty thousand tokens of context for a password reset" (Chapter 35, Topic 4): calls whose input is over what the context budget allows.
final class ContextSignature implements Signature
{
    public function name(): string
    {
        return 'context';
    }

    public function detect(AuditData $data): SignatureResult
    {
        $budget = (int) config('ai.context.call_budget', 6_000);

        $calls = $data->calls
            ->filter(fn (AuditCall $call) => in_array($call->feature, config('ai.cost.audit.context_features', ['support.reply']), true))
            ->filter(fn (AuditCall $call) => $call->inputTokens > 0);

        if ($calls->isEmpty()) {
            return SignatureResult::clear($this->name(), 'no answering calls in the window');
        }

        $p95 = (int) $data->percentile($calls->map(fn (AuditCall $call) => $call->inputTokens), 0.95);

        if ($p95 <= $budget) {
            return SignatureResult::clear($this->name(), sprintf('p95 input tokens per call %s (budget %s)', number_format($p95), number_format($budget)));
        }

        $over = $calls->filter(fn (AuditCall $call) => $call->inputTokens > $budget)->sortByDesc('usd')->values();

        return SignatureResult::flagged(
            $this->name(),
            sprintf('p95 input tokens per call %s (budget %s) — %d calls over', number_format($p95), number_format($budget), $over->count()),
            $over->take((int) config('ai.cost.audit.examples', 3))->map(fn (AuditCall $call) => $data->example($call))->all(),
            'retrieved context is not capped, or chunks got longer: check ai.context.sources_max_tokens and the help:ingest chunk sizes',
        );
    }
}
