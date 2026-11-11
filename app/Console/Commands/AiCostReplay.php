<?php

namespace App\Console\Commands;

use App\Ai\Cost\Replay\PostmortemReplay;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AiCostReplay extends Command
{
    protected $signature = 'ai:cost-replay
        {scenario? : healthy, loop, context, cache, drain or anonymous}
        {--wipe : Delete the replay rows and stop}';

    protected $description = 'Seed fake usage rows that reproduce one of the five Chapter 35 postmortems, for ai:cost-audit to find (development only)';

    public function handle(PostmortemReplay $replay): int
    {
        if (app()->isProduction()) {
            $this->error('The replay writes fake usage rows. It never runs in production.');

            return self::FAILURE;
        }

        if ($this->option('wipe')) {
            $this->info('Deleted '.$replay->wipe().' replay calls.');

            return self::SUCCESS;
        }

        try {
            $calls = $replay->run((string) ($this->argument('scenario') ?? 'healthy'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Seeded {$calls} replay calls. Now: php artisan ai:cost-audit --since=7d");

        return self::SUCCESS;
    }
}
