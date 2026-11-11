<?php

namespace App\Console\Commands;

use App\Ai\Registry\ArtifactVerifier;
use App\Ai\Registry\Registry;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RegistryVerify extends Command
{
    protected $signature = 'registry:verify
        {name : The fine-tune (billing)}
        {version : The version}';

    protected $description = 'Check a version\'s base, its artifact hashes and signatures, and the tools that made it (Chapter 44, Topic 8)';

    public function handle(Registry $registry, ArtifactVerifier $verifier): int
    {
        try {
            $entry = $registry->find((string) $this->argument('name'), (int) $this->argument('version'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $lines = $verifier->verify($entry);

        foreach ($lines as $line) {
            $this->line('  '.$line->text());
        }

        return $verifier->passed($lines)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
