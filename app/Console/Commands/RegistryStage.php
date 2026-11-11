<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RegistryStage extends Command
{
    protected $signature = 'registry:stage
        {name : The fine-tune (billing)}
        {version : The version}
        {--to= : shadow, canary, candidate (stops a trial) or rejected}
        {--percent= : Shadow: the share of real questions copied to the candidate. Canary: the share of customers served by it. 0 turns the share off}
        {--reason= : Why (required for rejected)}
        {--eval= : An eval:compare result to keep with the entry (eval:last, or a path)}
        {--by= : Who is making the change (default: the shell user)}';

    protected $description = 'Move a model version to shadow, canary, or back (Chapter 44, Topic 7)';

    public function handle(Registry $registry): int
    {
        try {
            $entry = $registry->stage(
                name: (string) $this->argument('name'),
                version: (int) $this->argument('version'),
                to: (string) $this->option('to'),
                by: $this->option('by') ?: (getenv('USER') ?: 'cli'),
                percent: $this->option('percent') === null ? null : (int) $this->option('percent'),
                reason: $this->option('reason'),
                evaluation: $this->option('eval') === 'eval:last' ? storage_path('eval/last-compare.json') : $this->option('eval'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("  {$entry->label()} · {$entry->stage}".($entry->percent === null ? '' : " · {$entry->percent}%").' · '.collect($entry->history)->last()['reason']);

        return self::SUCCESS;
    }
}
