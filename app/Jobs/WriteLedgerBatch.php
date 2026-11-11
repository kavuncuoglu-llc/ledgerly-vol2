<?php

namespace App\Jobs;

use App\Ai\Python\Streams;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

// Chapter 36, Topic 4: bulk-insert one batch of stream entries into ai_ledger, then acknowledge them. The entry id is unique on the table, so a batch that is written twice (a crash between the insert and the acknowledgement,
// or a redelivery) inserts nothing the second time. The payload is numbers and labels, never content.
final class WriteLedgerBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 30, 120];

    /**
     * @param  string[]  $ids  the stream entry ids
     * @param  array<int, array<string, mixed>>  $entries  the decoded entries, in the same order
     */
    public function __construct(public readonly array $ids, public readonly array $entries)
    {
        $this->onQueue((string) config('ai.ledger.queue', 'ledger'));
    }

    public function handle(Streams $streams): void
    {
        $rows = [];

        foreach ($this->ids as $position => $id) {
            $rows[] = ['entry_id' => $id] + $this->entries[$position];
        }

        DB::table('ai_ledger')->insertOrIgnore($rows);

        foreach ($this->ids as $id) {
            $streams->ack((string) config('ai.ledger.stream'), (string) config('ai.ledger.group'), $id);
        }
    }
}
