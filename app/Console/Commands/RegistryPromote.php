<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RegistryPromote extends Command
{
    protected $signature = 'registry:promote
        {name : The fine-tune (billing)}
        {version : The version}
        {--force : Promote without the decision record\'s criteria (the history says so)}
        {--reason= : Why}
        {--by= : Who is making the change (default: the shell user)}';

    protected $description = 'Move the production alias to a version; the old one becomes previous, kept warm (Chapter 44, Topic 7)';

    public function handle(Registry $registry): int
    {
        try {
            $entry = $registry->promote((string) $this->argument('name'), (int) $this->argument('version'), $this->option('by') ?: (getenv('USER') ?: 'cli'), (bool) $this->option('force'), $this->option('reason'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("  alias {$entry->name}@production → v{$entry->version} ({$entry->model}) · config cache refreshed");

        $previous = $registry->history($entry->name)->firstWhere('stage', 'previous');

        if ($previous !== null) {
            $this->line("  v{$previous->version} is previous: kept loaded and warm until {$previous->warm_until->toDateTimeString()}");
        }

        return self::SUCCESS;
    }
}
