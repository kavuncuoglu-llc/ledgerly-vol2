<?php

namespace App\Console\Commands;

use App\Ai\Training\Dataset;
use App\Ai\Training\DatasetPublisher;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class DatasetPublish extends Command
{
    protected $signature = 'dataset:publish
        {dataset : The dataset name under storage/datasets}
        {--region=eu : The region whose disk stores the customer-derived files (config/filesystems.php training-<region>)}
        {--hub-private= : A private Hugging Face repository for the files with no customer data (needs HF_TOKEN)}';

    protected $description = 'Write the manifest and hashes of a finished dataset and store it: customer-derived files in the region, the rest optionally in a private Hub repository (Chapter 40)';

    public function handle(DatasetPublisher $publisher): int
    {
        try {
            $manifest = $publisher->publish(Dataset::named((string) $this->argument('dataset')), (string) $this->option('region'), $this->option('hub-private') ?: null);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Published {$manifest['name']}: {$manifest['counts']['total']} examples, stored in {$manifest['stored']['disk']}".($manifest['hub'] === null ? '' : ", non-customer files in hf://{$manifest['hub']['repository']}@{$manifest['hub']['revision']}").'.');

        $this->line('Show it with: php artisan dataset:show '.$manifest['name']);

        return self::SUCCESS;
    }
}
