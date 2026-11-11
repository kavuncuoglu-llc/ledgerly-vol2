<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use App\Ai\Registry\Signing;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class RegistrySign extends Command
{
    protected $signature = 'registry:sign
        {name : The fine-tune (billing)}
        {version : The version}
        {--again : Sign artifacts that already have a signature}';

    protected $description = 'Sign each artifact of a version with the pipeline\'s key; servers verify it (Chapter 44, Topic 8)';

    public function handle(Registry $registry, Signing $signing): int
    {
        try {
            $entry = $registry->find((string) $this->argument('name'), (int) $this->argument('version'));

            $signatures = $signing->signAll($entry, (bool) $this->option('again'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($signatures as $key => $signature) {
            $this->line("  {$key} {$signature['sha256']} · signed by {$signature['key_id']} ({$signature['driver']})");
        }

        return self::SUCCESS;
    }
}
