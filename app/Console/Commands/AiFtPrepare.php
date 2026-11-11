<?php

namespace App\Console\Commands;

use App\Ai\FineTuning\FineTunePreparer;
use App\Ai\FineTuning\HostedUploadRefused;
use App\Ai\Training\Dataset;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiFtPrepare extends Command
{
    protected $signature = 'ai:ft:prepare
        {dataset : The dataset version under storage/datasets (billing-v1)}
        {--provider=frontier : The role whose fine-tuning API will receive the file}
        {--only=exemplars,synthetic,replay : The parts of the dataset the file may hold; production and edits need a recorded allowance (dataset:allow)}
        {--out=storage/ft/billing-hosted.jsonl : Where the training file is written}
        {--validation : Also write the validation file}
        {--base= : The base model, to price the training if its rate is in config/ai-pricing.php}
        {--epochs=3 : Epochs, for the token estimate}';

    protected $description = 'Convert a dataset version to a hosted provider\'s chat JSON lines, validate every row, count tokens and estimate the cost, before anything is uploaded (Chapter 41)';

    public function handle(FineTunePreparer $preparer): int
    {
        try {
            $result = $preparer->prepare(
                Dataset::named((string) $this->argument('dataset')),
                (string) $this->option('provider'),
                array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only'))))),
                (string) $this->option('out'),
                (bool) $this->option('validation'),
            );
        } catch (HostedUploadRefused $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $job = $result['job'];

        $invalid = $result['invalid'];

        $epochs = (int) $this->option('epochs');

        $this->line(sprintf('  rows %s · invalid %d · tokens %s per epoch · epochs %d · estimated training cost: %s', number_format($job->row_count), count($invalid), $this->millions($job->tokens_per_epoch), $epochs, $this->estimate($job->tokens_per_epoch * $epochs)));

        if ($result['excluded'] !== []) {
            $this->line('  excluded by consent rules: '.implode(' · ', array_map(fn (string $source, int $count) => "{$source} ".number_format($count), array_keys($result['excluded']), $result['excluded'])));
        }

        if ($invalid !== []) {
            foreach (array_slice($invalid, 0, 10) as $problem) {
                $this->error("  {$problem}");
            }

            $this->error(count($invalid).' invalid: nothing was written.');

            return self::FAILURE;
        }

        $this->line("  written {$job->file_path}".($job->validation_path === null ? '' : " and {$job->validation_path} ({$result['validation_rows']} rows)"));

        $this->line("  fine_tune_jobs #{$job->id} · prepared · dataset {$job->dataset_hash}");

        return self::SUCCESS;
    }

    private function millions(int $tokens): string
    {
        return $tokens >= 100_000
            ? number_format($tokens / 1_000_000, 2).'M'
            : number_format($tokens);
    }

    private function estimate(int $trainedTokens): string
    {
        $base = (string) $this->option('base');

        $rate = $base === ''
            ? null
            : (config('ai-pricing.training')[$base] ?? null);

        if ($rate === null) {
            return 'see Appendix F for the current rate';
        }

        return sprintf('$%.2f at $%s per million trained tokens (%s)', $trainedTokens * (float) $rate['per_million'] / 1_000_000, $rate['per_million'], $rate['source'] ?: 'no source');
    }
}
