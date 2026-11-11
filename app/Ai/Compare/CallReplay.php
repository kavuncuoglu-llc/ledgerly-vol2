<?php

namespace App\Ai\Compare;

use App\Models\ModelCall;
use Carbon\CarbonImmutable;

// Real requests, recorded when ai.debug.record_requests was on, as golden cases without answers. They hold customer text: the command that uses them refuses hosted providers.
final class CallReplay
{
    /** @return list<GoldenCase> */
    public static function cases(?string $feature, CarbonImmutable $since, int $limit = 50): array
    {
        return ModelCall::query()
            ->whereNotNull('request')
            ->where('created_at', '>=', $since)
            ->when($feature, fn ($query, $feature) => $query->where('feature', $feature))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ModelCall $call) => new GoldenCase(
                id: "call-{$call->id}",
                feature: $call->feature,
                category: 'replayed',
                type: GoldenCase::REPLAY,
                input: $call->request + ['question' => self::lastUserText($call->request['messages'] ?? [])],
                expected: [],
            ))
            ->all();
    }

    private static function lastUserText(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                return $message['content'];
            }
        }

        return '';
    }
}
