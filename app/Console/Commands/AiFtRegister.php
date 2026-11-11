<?php

namespace App\Console\Commands;

use App\Ai\FineTuning\FineTuneRegistrar;
use App\Models\FineTuneJob;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiFtRegister extends Command
{
    protected $signature = 'ai:ft:register
        {job : The fine_tune_jobs id of a succeeded job}
        {--role=frontier-billing-ft : The role in config/ai.php that takes the model}
        {--env= : The environment file to write (default .env)}
        {--print : Print the variables instead of writing them}';

    protected $description = 'Write a succeeded hosted fine-tune into the role: model, base, job, dataset and hash, and empty price variables that fail the audit until filled (Chapter 41)';

    public function handle(FineTuneRegistrar $registrar): int
    {
        $job = FineTuneJob::query()->find((int) $this->argument('job'));

        if ($job === null) {
            $this->error('No fine-tuning job #'.$this->argument('job').'.');

            return self::FAILURE;
        }

        $role = (string) $this->option('role');

        try {
            if ($this->option('print')) {
                foreach ($registrar->variables($job, $role) as $name => $value) {
                    $this->line("{$name}={$value}");
                }

                return self::SUCCESS;
            }

            $path = (string) ($this->option('env') ?: base_path('.env'));

            $missing = $registrar->register($job, $role, $path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("  role {$role} → {$job->model_id} (base {$job->base_model}, dataset {$job->dataset} {$job->dataset_hash}, job {$job->provider_job_id}) written to {$path}");

        if ($missing !== []) {
            $this->warn('  no price yet: '.implode(', ', $missing).' are empty, so php artisan ai:cost-audit fails on this model until they are filled from the provider\'s page (Appendix F.8). Then php artisan config:clear.');
        }

        return self::SUCCESS;
    }
}
