<?php

namespace App\Ai\FineTuning;

use App\Models\FineTuneJob;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 41, Topic 5: a hosted job's model ID becomes a role. The role itself is in config/ai.php and reads its model, base, dataset and job from the environment, so registering writes those variables (to .env, or prints them) and leaves the two price variables empty on purpose: a fine-tune with no price is unpriced, and ai:cost-audit fails on it until they are filled from the provider's page.
final class FineTuneRegistrar
{
    /** @return array<string, string> the variables written, in order */
    public function variables(FineTuneJob $job, string $role): array
    {
        $this->assertRegistrable($job, $role);

        return [
            'BILLING_FT_MODEL' => (string) $job->model_id,
            'BILLING_FT_BASE' => (string) $job->base_model,
            'BILLING_FT_JOB' => (string) $job->provider_job_id,
            'BILLING_FT_DATASET' => $job->dataset,
            'BILLING_FT_DATASET_HASH' => $job->dataset_hash,
        ];
    }

    /** @return list<string> the price variables still empty after registering */
    public function register(FineTuneJob $job, string $role, ?string $envPath): array
    {
        $variables = $this->variables($job, $role);

        $missing = [];

        if ($envPath !== null) {
            $this->write($envPath, $variables);

            $missing = $this->placeholders($envPath);
        }

        $job->update(['role' => $role]);

        return $missing;
    }

    private function assertRegistrable(FineTuneJob $job, string $role): void
    {
        if ($job->status !== 'succeeded') {
            throw new InvalidArgumentException("Job #{$job->id} is {$job->status}: only a succeeded job has a model to register.");
        }

        if (blank($job->model_id)) {
            throw new InvalidArgumentException("Job #{$job->id} succeeded without a model ID: look at its events.");
        }

        if (! isset(config('ai.providers')[$role]['fine_tuned'])) {
            $known = array_keys(array_filter(config('ai.providers'), fn ($provider) => is_array($provider) && isset($provider['fine_tuned'])));

            throw new InvalidArgumentException("[{$role}] is not a fine-tuned role in config/ai.php. Roles with a fine_tuned block: ".implode(', ', $known).'.');
        }
    }

    private function write(string $envPath, array $variables): void
    {
        $contents = is_file($envPath)
            ? (string) file_get_contents($envPath)
            : '';

        foreach ($variables as $name => $value) {
            $line = "{$name}={$value}";

            $contents = preg_match("/^{$name}=.*$/m", $contents) === 1
                ? (string) preg_replace("/^{$name}=.*$/m", $line, $contents)
                : rtrim($contents, "\n")."\n{$line}\n";
        }

        foreach (['BILLING_FT_RATE_IN', 'BILLING_FT_RATE_OUT', 'BILLING_FT_TRAIN_RATE'] as $name) {
            if (preg_match("/^{$name}=/m", $contents) !== 1) {
                $contents = rtrim($contents, "\n")."\n{$name}=\n";
            }
        }

        File::put($envPath, ltrim($contents, "\n"));
    }

    /** @return list<string> */
    private function placeholders(string $envPath): array
    {
        $contents = (string) file_get_contents($envPath);

        return array_values(array_filter(
            ['BILLING_FT_RATE_IN', 'BILLING_FT_RATE_OUT', 'BILLING_FT_TRAIN_RATE'],
            fn (string $name) => preg_match("/^{$name}=\s*$/m", $contents) === 1,
        ));
    }
}
