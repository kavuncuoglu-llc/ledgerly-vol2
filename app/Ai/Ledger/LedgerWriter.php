<?php

namespace App\Ai\Ledger;

use App\Ai\Events\ModelCalled;
use App\Ai\Python\Streams;
use App\Models\AiLedgerEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Chapter 36, Topic 4: the request path appends one entry to a Redis stream and goes on; nothing here touches Postgres. A consumer writes the stream to ai_ledger in batches (WriteLedgerBatch). If Redis is unreachable the row is written directly,
// once, so a call is never missing from the ledger: the book's "if the consumer is down, entries wait in the stream" covers a down consumer, and this covers a down stream.
final class LedgerWriter
{
    private const DOWN = 'ai.ledger.stream_down';

    public function __construct(private Streams $streams, private Costing $costing) {}

    public function append(ModelCalled $event): void
    {
        if (! config('ai.ledger.enabled', true)) {
            return;
        }

        if ($this->nothingToRecord($event)) {
            return;                                                              // a cache hit or a degraded answer made no model call
        }

        $entry = LedgerEntry::fromEvent($event, $this->costing);

        if (Cache::has(self::DOWN)) {
            AiLedgerEntry::query()->create($entry);

            return;
        }

        try {
            $this->streams->add((string) config('ai.ledger.stream'), ['entry' => json_encode($entry, JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $e) {
            Cache::put(self::DOWN, true, 30);                                    // do not wait for a dead Redis on every call: write directly for half a minute

            Log::warning('The ledger stream is unreachable: writing the entry directly', ['error' => $e->getMessage()]);

            AiLedgerEntry::query()->create($entry);
        }
    }

    private function nothingToRecord(ModelCalled $event): bool
    {
        return (int) ($event->usage['prompt_tokens'] ?? 0) === 0
            && (int) ($event->usage['completion_tokens'] ?? 0) === 0;
    }
}
