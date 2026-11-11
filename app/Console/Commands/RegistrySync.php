<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use Illuminate\Console\Command;

class RegistrySync extends Command
{
    protected $signature = 'registry:sync';

    protected $description = 'Rewrite the alias file from the registry and refresh config: run it on each host after a promotion or a rollback (Chapter 44, Topic 5)';

    public function handle(Registry $registry): int
    {
        $registry->publish();

        $this->info('Aliases written; config refreshed.');

        return self::SUCCESS;
    }
}
