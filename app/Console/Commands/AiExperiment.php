<?php

namespace App\Console\Commands;

use App\Ai\Experiments\Candidate;
use App\Ai\Experiments\Experiment;
use App\Models\ExperimentSetting;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiExperiment extends Command
{
    protected $signature = 'ai:experiment
        {name : An experiment from config/ai.php (ai.experiments) or a new name}
        {--mode= : shadow (re-answer a share of real requests unseen), ab (show the candidate to a share of customers) or off}
        {--percent= : Shadow: the share of requests re-answered. A/B: the share of customers in the treatment}
        {--candidate= : A PHP file that returns the candidate prompt, or a prompt class}';

    protected $description = 'Switch a shadow run or an A/B test on or off (Chapter 30, Topic 4)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        $mode = $this->option('mode');

        if ($mode === null) {
            return $this->show($name);
        }

        if (! in_array($mode, ['shadow', 'ab', 'off'], true)) {
            $this->error('--mode is shadow, ab or off.');

            return self::FAILURE;
        }

        $percent = $this->option('percent') !== null
            ? (int) $this->option('percent')
            : (int) (config("ai.experiments.{$name}.treatment_percent") ?? 10);

        if ($percent < 0 || $percent > 100) {
            $this->error('--percent is 0 to 100.');

            return self::FAILURE;
        }

        $candidate = $this->option('candidate') ?: ExperimentSetting::query()->where('name', $name)->value('candidate') ?: config("ai.experiments.{$name}.treatment.candidate");

        if ($mode !== 'off') {
            try {
                Candidate::from((string) $candidate);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $existing = ExperimentSetting::query()->where('name', $name)->first();

        if ($existing === null) {
            ExperimentSetting::query()->create(['name' => $name, 'mode' => $mode, 'percent' => $percent, 'candidate' => $candidate]);
        }

        if ($existing !== null) {
            $existing->update(['mode' => $mode, 'percent' => $percent, 'candidate' => $candidate]);
        }

        return $this->show($name);
    }

    private function show(string $name): int
    {
        $settings = Experiment::settings($name);

        if ($settings === null) {
            $this->line("{$name}: off");

            return self::SUCCESS;
        }

        $candidate = Candidate::from((string) ($settings['treatment']['candidate'] ?? ''));

        $line = $settings['mode'] === 'shadow'
            ? "{$name}: shadow, {$settings['treatment_percent']}% of requests re-answered by {$candidate->name} ({$candidate->version}); customers never see it"
            : "{$name}: A/B, {$settings['treatment_percent']}% of customers get {$candidate->name} ({$candidate->version}), sticky by customer";

        $this->line($line);

        if ($settings['mode'] === 'ab') {
            $this->line('  stop early only if: '.implode(' or ', $settings['stop_on'] ?? []).' crosses its floor; plan for '.($settings['min_per_variant'] ?? '?').' conversations per variant.');
        }

        return self::SUCCESS;
    }
}
