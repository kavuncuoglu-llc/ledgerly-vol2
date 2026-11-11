<?php

namespace App\Console\Commands;

use App\Jobs\ComputeOnlineSignals;
use Illuminate\Console\Command;

class EvalSignals extends Command
{
    protected $signature = 'eval:signals {--date= : The day to compute (default: yesterday)}';

    protected $description = 'Compute the online signals for a day now instead of waiting for the nightly job (Chapter 30, Topic 3)';

    public function handle(): int
    {
        $job = new ComputeOnlineSignals($this->option('date') ?: null);

        $count = app()->call([$job, 'handle']);

        $this->info("Computed signals for {$count} answers.");

        return self::SUCCESS;
    }
}
