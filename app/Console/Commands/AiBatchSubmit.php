<?php

namespace App\Console\Commands;

use App\Ai\Batch\BatchService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiBatchSubmit extends Command
{
    protected $signature = 'ai:batch:submit
        {name : A batch kind: judge-nightly, synthesize-help-center}
        {--provider= : The role that runs it (default: the kind\'s own)}
        {--from= : What to build the requests from: golden:all or replies:1d for the judge; help-center or help-center:200 for synthesis}';

    protected $description = 'Write a batch of requests for work nobody is waiting on (JSONL with our own id on each), submit it and record it (Chapter 37)';

    public function handle(BatchService $batches): int
    {
        try {
            $batch = $batches->submit((string) $this->argument('name'), (string) ($this->option('from') ?: ''), $this->option('provider') ?: null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('%s: %s requests written to %s, submitted to %s (%s driver), batch %d, provider id %s', $batch->name, number_format($batch->request_count), basename((string) $batch->input_path), $batch->provider, $batch->driver, $batch->id, $batch->provider_batch_id));

        return self::SUCCESS;
    }
}
