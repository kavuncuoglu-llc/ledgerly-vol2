<?php

namespace App\Console\Commands;

use App\Ai\Batch\BatchService;
use App\Models\AiBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AiBatchPoll extends Command
{
    protected $signature = 'ai:batch:poll
        {--collect : Collect a batch as soon as it completes}';

    protected $description = 'Ask each submitted batch how it is getting on; a batch on your own hardware works through a share of its requests (Chapter 37; every ten minutes)';

    public function handle(BatchService $batches): int
    {
        $waiting = AiBatch::query()->where('status', 'submitted')->count();

        if ($waiting === 0) {
            $this->line('No batch is waiting.');

            return self::SUCCESS;
        }

        $changed = $batches->poll();

        $this->line(sprintf('%d submitted, %d changed state', $waiting, count($changed)));

        foreach ($changed as $batch) {
            $this->line(sprintf('  %s (batch %d): %s', $batch->name, $batch->id, $batch->status));

            if ($this->option('collect') && $batch->status === 'completed') {
                Artisan::call('ai:batch:collect', ['name' => (string) $batch->id], $this->output);
            }
        }

        return self::SUCCESS;
    }
}
