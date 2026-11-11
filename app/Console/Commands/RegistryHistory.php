<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use Illuminate\Console\Command;

class RegistryHistory extends Command
{
    protected $signature = 'registry:history {name : The fine-tune (billing)}';

    protected $description = 'Every version of a fine-tune and every change of stage, with who and why (Chapter 44, Topic 6)';

    public function handle(Registry $registry): int
    {
        $versions = $registry->history((string) $this->argument('name'));

        if ($versions->isEmpty()) {
            $this->error("There is no {$this->argument('name')} in the registry.");

            return self::FAILURE;
        }

        foreach ($versions as $entry) {
            $this->line("{$entry->label()} · {$entry->stage}".($entry->percent === null ? '' : " {$entry->percent}%")." · {$entry->model} · {$entry->base}".($entry->base_revision ? '@'.substr($entry->base_revision, 0, 7) : '')." · {$entry->dataset} {$entry->dataset_hash} · prompt {$entry->prompt_version}");

            foreach ((array) $entry->history as $event) {
                $this->line('  '.substr((string) $event['at'], 0, 16).'  '.str_pad($event['stage'], 11).str_pad((string) $event['by'], 16).$event['reason']);
            }
        }

        return self::SUCCESS;
    }
}
