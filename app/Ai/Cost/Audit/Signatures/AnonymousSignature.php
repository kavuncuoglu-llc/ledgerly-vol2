<?php

namespace App\Ai\Cost\Audit\Signatures;

use App\Ai\Cost\Audit\AuditCall;
use App\Ai\Cost\Audit\AuditData;
use App\Ai\Cost\Audit\Signature;
use App\Ai\Cost\Audit\SignatureResult;

// "Denial of wallet" (Chapter 35, Topic 7): the anonymous share of spend. A jump in it is the first sign that the public widget is being used as somebody's free model.
final class AnonymousSignature implements Signature
{
    public function name(): string
    {
        return 'anonymous';
    }

    public function detect(AuditData $data): SignatureResult
    {
        $anonymous = $data->calls->filter(fn (AuditCall $call) => in_array($call->feature, config('ai.cost.audit.anonymous_features', ['widget.ask']), true));

        $total = $data->spend();

        $share = $total > 0
            ? $anonymous->sum('usd') / $total
            : 0.0;

        $alert = (float) config('ai.cost.audit.anonymous_alert_share', 0.10);

        $summary = sprintf('%d%% of spend (alert above %d%%)', round($share * 100), round($alert * 100));

        if ($share <= $alert) {
            return SignatureResult::clear($this->name(), $summary);
        }

        return SignatureResult::flagged(
            $this->name(),
            $summary.sprintf(' — %s calls, $%.2f', number_format($anonymous->count()), $anonymous->sum('usd')),
            $anonymous->sortByDesc('usd')->take((int) config('ai.cost.audit.examples', 3))->map(fn (AuditCall $call) => $data->example($call))->values()->all(),
            'anonymous traffic is spending like customers: the widget must use the local role only, with a short max_tokens, a challenge and its own budget',
        );
    }
}
