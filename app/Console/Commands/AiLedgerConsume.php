<?php

namespace App\Console\Commands;

use App\Ai\Python\Streams;
use App\Jobs\WriteLedgerBatch;
use Illuminate\Console\Command;

class AiLedgerConsume extends Command
{
    protected $signature = 'ai:ledger-consume
        {--once : Write what is waiting and stop (cron, tests); without it the command runs until it is stopped}
        {--batch= : Entries per WriteLedgerBatch job (default ai.ledger.batch)}
        {--block=2000 : Milliseconds to wait for new entries}';

    protected $description = 'Read the ledger stream in batches and hand each batch to a WriteLedgerBatch job on the ledger queue (Chapter 36)';

    private bool $stopping = false;

    public function handle(Streams $streams): int
    {
        $stream = (string) config('ai.ledger.stream');

        $group = (string) config('ai.ledger.group');

        $consumer = (string) gethostname();                                       // stable across restarts, so a killed consumer's unacknowledged entries come back to the same name

        $batch = (int) ($this->option('batch') ?: config('ai.ledger.batch', 500));

        $streams->ensureGroup($stream, $group);

        $this->stopOnSignal();

        $dispatched = $this->dispatch($streams->readPending($stream, $group, $consumer, 10_000));       // what a killed run read and never wrote, once, at the start: a job already queued will acknowledge its own

        do {
            $entries = $streams->readGroup($stream, $group, $consumer, $batch, $this->option('once') ? 0 : (int) $this->option('block'));

            $dispatched += $this->dispatch($entries);
        } while (! $this->stopping && ! ($this->option('once') && $entries === []));

        $this->info("Handed {$dispatched} ledger entries to the {$this->queue()} queue.");

        return self::SUCCESS;
    }

    private function dispatch(array $entries): int
    {
        foreach (array_chunk($entries, (int) ($this->option('batch') ?: config('ai.ledger.batch', 500))) as $chunk) {
            WriteLedgerBatch::dispatch(array_column($chunk, 'id'), array_map(fn (array $entry) => json_decode($entry['fields']['entry'], true), $chunk));
        }

        return count($entries);
    }

    private function queue(): string
    {
        return (string) config('ai.ledger.queue', 'ledger');
    }

    private function stopOnSignal(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, fn () => $this->stopping = true);
        pcntl_signal(SIGINT, fn () => $this->stopping = true);
    }
}
