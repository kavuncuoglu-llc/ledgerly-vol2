<?php

namespace App\Console\Commands;

use App\Ai\Prompts\PromptLock;
use Illuminate\Console\Command;

class PromptsLock extends Command
{
    protected $signature = 'prompts:lock {--check : Only report prompts whose text changed without a new VERSION}';

    protected $description = 'Record the reviewed hash of every prompt version in prompts.lock (Chapter 29, Topic 3)';

    public function handle(): int
    {
        $lock = PromptLock::read();

        $problems = [];

        foreach (PromptLock::prompts() as $class) {
            $name = class_basename($class);

            $version = $class::VERSION;

            $hash = PromptLock::hashOf($class);

            $reviewed = $lock[$name][$version] ?? null;

            if ($reviewed !== null && $reviewed !== $hash) {
                $problems[] = "{$name} {$version}: the text changed but VERSION did not. Bump VERSION, then lock it.";

                continue;
            }

            $lock[$name][$version] = $hash;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->info('Every prompt version matches prompts.lock.');

            return self::SUCCESS;
        }

        PromptLock::write($lock);

        $this->info('prompts.lock is up to date.');

        return self::SUCCESS;
    }
}
