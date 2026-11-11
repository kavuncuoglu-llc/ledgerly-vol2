<?php

namespace App\Ai\Experiments;

use App\Models\Customer;
use App\Models\ExperimentSetting;
use Illuminate\Support\Facades\Schema;

// Chapter 30, Topic 4. config('ai.experiments') says what an experiment is; the experiments table (php artisan ai:experiment) says whether it is running and how.
final class Experiment
{
    public const CONTROL = 'control';

    public const TREATMENT = 'treatment';

    // Sticky assignment: a hash of the experiment and the customer decides, so one customer never flips between prompts mid-conversation.
    public static function variant(Customer $customer, string $experiment): string
    {
        $config = self::settings($experiment);

        if ($config === null) {
            return self::CONTROL;
        }

        if (($config['mode'] ?? 'ab') !== 'ab') {
            return self::CONTROL;
        }

        $bucket = hexdec(substr(hash('sha256', "{$experiment}:{$customer->id}"), 0, 8)) % 100;

        return $bucket < $config['treatment_percent']
            ? self::TREATMENT
            : self::CONTROL;
    }

    /** @return array<string, mixed>|null the merged settings of an experiment that is switched on, else null */
    public static function settings(string $experiment): ?array
    {
        $config = config("ai.experiments.{$experiment}");

        $row = self::row($experiment);

        if ($config === null && $row === null) {
            return null;
        }

        $merged = array_merge(
            ['mode' => 'ab', 'treatment_percent' => 0, 'feature' => 'support.reply'],
            $config ?? [],
            $row === null ? [] : array_filter(['mode' => $row->mode, 'treatment_percent' => $row->percent, 'treatment' => $row->candidate ? ['candidate' => $row->candidate] : null], fn ($value) => $value !== null),
        );

        $active = $row === null
            ? (bool) ($merged['active'] ?? false)
            : $row->mode !== 'off';

        return $active
            ? $merged + ['name' => $experiment]
            : null;
    }

    /** @return array<string, mixed>|null the experiment running on a feature in the given mode */
    public static function running(string $feature, string $mode): ?array
    {
        foreach (self::names() as $name) {
            $settings = self::settings($name);

            if ($settings !== null && $settings['feature'] === $feature && $settings['mode'] === $mode) {
                return $settings;
            }
        }

        return null;
    }

    public static function candidateFor(string $feature): ?Candidate
    {
        foreach (['shadow', 'ab'] as $mode) {
            $settings = self::running($feature, $mode);

            if ($settings !== null && isset($settings['treatment']['candidate'])) {
                return Candidate::from($settings['treatment']['candidate']);
            }
        }

        return null;
    }

    // Shadow runs sample requests, not customers: the same reply is always in or always out.
    public static function shadowSampled(int $messageId, int $percent): bool
    {
        return hexdec(substr(hash('sha256', "shadow:{$messageId}"), 0, 8)) % 100 < $percent;
    }

    /** @return list<string> */
    public static function names(): array
    {
        $fromTable = Schema::hasTable('experiments')
            ? ExperimentSetting::query()->pluck('name')->all()
            : [];

        return array_values(array_unique([...array_keys(config('ai.experiments', [])), ...$fromTable]));
    }

    private static function row(string $experiment): ?ExperimentSetting
    {
        if (! Schema::hasTable('experiments')) {
            return null;
        }

        return ExperimentSetting::query()->where('name', $experiment)->first();
    }
}
