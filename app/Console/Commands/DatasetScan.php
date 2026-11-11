<?php

namespace App\Console\Commands;

use App\Ai\Training\Dataset;
use App\Ai\Training\InjectionDetector;
use App\Ai\Training\TrainingScan;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DatasetScan extends Command
{
    protected $signature = 'dataset:scan
        {dataset : The dataset name under storage/datasets}
        {--checks=injection,rare-sequences,unsourced-links,concentration : Any of injection, rare-sequences, unsourced-links, concentration, placeholders}
        {--stage=export : export (before review), reviewed, cleaned or final}
        {--strict : Exit 1 when any row is flagged}';

    protected $description = 'Scan a training set for planted instructions, trigger phrases, unsourced links and contact details, and concentration (Chapter 40, Topic 9)';

    public function handle(InjectionDetector $injection): int
    {
        try {
            $dataset = Dataset::named((string) $this->argument('dataset'));

            $records = $dataset->records((string) $this->option('stage'));

            $checks = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('checks')))));

            $unknown = array_diff($checks, TrainingScan::CHECKS);

            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown check: '.implode(', ', $unknown).'. Use '.implode(', ', TrainingScan::CHECKS).'.');
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = (new TrainingScan($injection, (float) config('ai.training.caps.tenant'), (float) config('ai.training.caps.user')))->run($records, $checks);

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        $dataset->writeJson('scan.json', $report->toArray());

        if ($this->option('strict') && $report->flagged() > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
