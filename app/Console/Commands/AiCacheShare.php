<?php

namespace App\Console\Commands;

use App\Ai\Budgets\Notifier;
use App\Ai\Budgets\Teams;
use App\Models\ModelCall;
use Illuminate\Console\Command;

class AiCacheShare extends Command
{
    protected $signature = 'ai:cache-share
        {--hours=24 : The window}
        {--notify : Tell the owning team when a feature is under its floor}';

    protected $description = 'The share of input tokens served from the prompt cache per feature against its floor, and an alert when one drops under it (Chapter 37, Topic 4)';

    public function handle(Notifier $notifier): int
    {
        $minimum = (int) config('ai.cost.audit.min_prefix_tokens', 1024);

        $rows = [];

        $below = false;

        foreach ($this->floors() as $feature => $floor) {
            $calls = ModelCall::query()->where('feature', $feature)->where('created_at', '>=', now()->subHours((int) $this->option('hours')))->where('prompt_tokens', '>=', $minimum)->get(['prompt_tokens', 'cached_input_tokens']);

            if ($calls->isEmpty()) {
                continue;
            }

            $share = $calls->sum('cached_input_tokens') / $calls->sum('prompt_tokens');

            $ok = $share >= $floor;

            $rows[] = [$feature, number_format($calls->count()), round($share * 100).'%', round($floor * 100).'%', $ok ? '✓' : '✗'];

            if (! $ok) {
                $below = true;

                if ($this->option('notify')) {
                    $notifier->team(Teams::of($feature), "Prompt cache share for {$feature} is under its floor", sprintf('%s: %d%% of input tokens were served from the cache in the last %d hours, against a floor of %d%%. A prefix that varies per call is the usual cause: run php artisan ai:cost-audit and the CacheablePrefixTest.', $feature, round($share * 100), (int) $this->option('hours'), round($floor * 100)));
                }
            }
        }

        if ($rows === []) {
            $this->line('No calls long enough to cache in the window.');

            return self::SUCCESS;
        }

        $this->table(['feature', 'calls', 'cached share', 'floor', ''], $rows);

        return $below
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @return array<string, float> */
    private function floors(): array
    {
        $floors = [];

        foreach (config('ai.scorecards', []) as $feature => $metrics) {
            if (isset($metrics['cached_input_share']['floor'])) {
                $floors[$feature] = (float) $metrics['cached_input_share']['floor'];
            }
        }

        return $floors + (array) config('ai.cost.audit.cached_share_floors', []);
    }
}
