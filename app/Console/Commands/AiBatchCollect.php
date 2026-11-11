<?php

namespace App\Console\Commands;

use App\Ai\Batch\BatchService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiBatchCollect extends Command
{
    protected $signature = 'ai:batch:collect {name : The batch kind or a batch id}';

    protected $description = 'Collect the results of a completed batch: idempotent by custom id, failures resubmitted, every result in the ledger at the batch price (Chapter 37)';

    public function handle(BatchService $batches): int
    {
        try {
            $summary = $batches->collect((string) $this->argument('name'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %s results · %s failed%s · ledger: %s rows at batch price · saving vs interactive: %s',
            number_format($summary['results']),
            number_format($summary['failed']),
            $summary['resubmitted'] === null ? '' : ' (resubmitted as batch '.$summary['resubmitted']->id.')',
            number_format($summary['ledger_rows']),
            $summary['saving'] === null ? 'n/a (your own hardware has no token price)' : round($summary['saving'] * 100).'%',
        ));

        return self::SUCCESS;
    }
}
