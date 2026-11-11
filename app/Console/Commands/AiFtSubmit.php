<?php

namespace App\Console\Commands;

use App\Ai\FineTuning\DuplicateFineTune;
use App\Ai\FineTuning\FineTuneSpec;
use App\Ai\FineTuning\FineTuneSubmitter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class AiFtSubmit extends Command
{
    protected $signature = 'ai:ft:submit
        {dataset : The dataset version the file was prepared from}
        {--provider=frontier : The role whose fine-tuning API receives the job}
        {--base= : The base model, from the provider\'s list of tunable models (BILLING_FT_BASE)}
        {--method=supervised : supervised, or preference where the provider offers it}
        {--epochs= : Epochs (the provider defaults it from the dataset size when unset)}
        {--lr-multiplier= : A multiplier on the provider\'s own base learning rate}
        {--batch-size= : Batch size, where the provider exposes it}
        {--seed= : A seed, where the provider exposes it}
        {--suffix= : A suffix for the model name}
        {--no-validation : Do not send the validation file}
        {--again : Submit even though the same dataset hash, base and settings were submitted before}';

    protected $description = 'Upload a prepared file and create a hosted fine-tuning job; refused when the same job already exists (Chapter 41)';

    public function handle(FineTuneSubmitter $submitter): int
    {
        $base = (string) ($this->option('base') ?: config('ai.providers.frontier-billing-ft.fine_tuned.base'));

        if ($base === '') {
            $this->error('Name the base model: --base=<id> or BILLING_FT_BASE (the models a provider lets you tune are in Appendix F.8).');

            return self::FAILURE;
        }

        $spec = new FineTuneSpec(
            baseModel: $base,
            method: (string) $this->option('method'),
            epochs: $this->option('epochs') === null ? null : (int) $this->option('epochs'),
            learningRateMultiplier: $this->option('lr-multiplier') === null ? null : (float) $this->option('lr-multiplier'),
            batchSize: $this->option('batch-size') === null ? null : (int) $this->option('batch-size'),
            seed: $this->option('seed') === null ? null : (int) $this->option('seed'),
            suffix: $this->option('suffix'),
        );

        try {
            $job = $submitter->submit((string) $this->argument('dataset'), (string) $this->option('provider'), $spec, ! $this->option('no-validation'), (bool) $this->option('again'));
        } catch (DuplicateFineTune|InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("  job {$job->provider_job_id} {$job->status} · dataset {$job->dataset_hash} · recorded in fine_tune_jobs #{$job->id}");

        return self::SUCCESS;
    }
}
