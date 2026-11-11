<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use Illuminate\Console\Command;

class RegistryRetire extends Command
{
    protected $signature = 'registry:retire';

    protected $description = 'Unload and archive previous versions whose warm period has ended (Chapter 44, Topic 6)';

    public function handle(Registry $registry): int
    {
        $retired = $registry->retireExpired();

        foreach ($retired as $entry) {
            $this->line("  {$entry->label()} retired: unloaded".($entry->archived_at === null ? '' : ', artifacts archived'));
        }

        $this->info($retired->count().' retired.');

        return self::SUCCESS;
    }
}
