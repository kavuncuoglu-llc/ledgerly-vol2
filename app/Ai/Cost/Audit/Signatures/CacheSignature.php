<?php

namespace App\Ai\Cost\Audit\Signatures;

use App\Ai\Cost\Audit\AuditCall;
use App\Ai\Cost\Audit\AuditData;
use App\Ai\Cost\Audit\Signature;
use App\Ai\Cost\Audit\SignatureResult;

// "The prefix that never cached" (Chapter 35, Topic 5): a feature whose cached share of input fell under the scorecard's floor. The release that did it is the first one whose calls are all under it.
final class CacheSignature implements Signature
{
    public function name(): string
    {
        return 'cache';
    }

    public function detect(AuditData $data): SignatureResult
    {
        $minimum = (int) config('ai.cost.audit.min_prefix_tokens', 1024);

        $messages = [];

        $flagged = [];

        foreach ($this->floors() as $feature => $floor) {
            $calls = $data->calls->filter(fn (AuditCall $call) => $call->feature === $feature && $call->inputTokens >= $minimum);

            if ($calls->isEmpty()) {
                continue;
            }

            $share = $calls->sum('cachedInputTokens') / $calls->sum('inputTokens');

            if ($share >= $floor) {
                $messages[] = sprintf('%s cached share %d%% (floor %d%%)', $feature, round($share * 100), round($floor * 100));

                continue;
            }

            $flagged[] = $this->describe($data, $feature, $calls, $share, $floor);
        }

        if ($flagged === []) {
            return SignatureResult::clear($this->name(), $messages === [] ? 'no calls long enough to cache' : implode('; ', $messages));
        }

        return SignatureResult::flagged(
            $this->name(),
            implode('; ', array_column($flagged, 'summary')),
            array_merge(...array_column($flagged, 'examples')),
            'the stable prefix changed: something that varies sits before the cache breakpoint (see CacheablePrefixTest)',
        );
    }

    /** @return array<string, float> */
    private function floors(): array
    {
        $floors = [];

        foreach (config('ai.scorecards', []) as $feature => $metrics) {
            if (isset($metrics['cached_input_share']['floor'])) {
                $floors[$feature] = (float) $metrics['cached_input_share']['floor'];
            }
        }

        return $floors + (array) config('ai.cost.audit.cached_share_floors', []);
    }

    private function describe(AuditData $data, string $feature, $calls, float $share, float $floor): array
    {
        $byVersion = $calls->groupBy(fn (AuditCall $call) => (string) $call->promptVersion)
            ->map(fn ($group) => $group->sum('cachedInputTokens') / max(1, $group->sum('inputTokens')))
            ->sort();

        $version = (string) $byVersion->keys()->first();

        $since = $calls->filter(fn (AuditCall $call) => (string) $call->promptVersion === $version)->min('at');

        return [
            'summary' => sprintf('%s cached share %d%% (floor %d%%) since release %s — prompt %s', $feature, round($share * 100), round($floor * 100), $since->toDateString(), $version === '' ? 'unversioned' : $version),
            'examples' => $calls->filter(fn (AuditCall $call) => (string) $call->promptVersion === $version)->sortByDesc('usd')->take((int) config('ai.cost.audit.examples', 3))->map(fn (AuditCall $call) => $data->example($call))->values()->all(),
        ];
    }
}
