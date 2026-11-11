<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HelpEmbed extends Command
{
    protected $signature = 'help:embed
        {--article= : Only this article, as category/slug}
        {--dry-run : Chunk the articles and report, without calling the embedding model}';

    protected $description = 'Chapter 6 name for help:ingest --force: embed every chunk again';

    public function handle(): int
    {
        $this->line('help:embed is now help:ingest --force (Chapter 15).');

        return $this->call('help:ingest', array_filter([
            '--force' => true,
            '--article' => $this->option('article'),
            '--dry-run' => $this->option('dry-run'),
        ]));
    }
}
