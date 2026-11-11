<?php

namespace App\Console\Commands;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Registry\Registry;
use App\Facades\Ai;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class RegistryRollback extends Command
{
    protected $signature = 'registry:rollback
        {name : The fine-tune (billing)}
        {--reason= : Why (required)}
        {--to= : The version to go back to (default: the previous one, kept warm)}
        {--time : Send one request to the role and print how long it took for the old version to answer it}
        {--by= : Who is making the change (default: the shell user)}';

    protected $description = 'Move the production alias back to the previous version, in seconds (Chapter 44, Topic 7)';

    public function handle(Registry $registry): int
    {
        if (blank($this->option('reason'))) {
            $this->error('A rollback records why: --reason="…".');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $entry = $registry->rollback((string) $this->argument('name'), (string) $this->option('reason'), $this->option('by') ?: (getenv('USER') ?: 'cli'), $this->option('to') === null ? null : (int) $this->option('to'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $line = "  alias {$entry->name}@production → v{$entry->version} · config cache refreshed";

        if (! $this->option('time')) {
            $this->line($line);

            return self::SUCCESS;
        }

        return $this->timed($line, $entry->version, $entry->name, $started);
    }

    private function timed(string $line, int $version, string $name, float $started): int
    {
        $role = (string) config('ai.registry.roles')[$name];

        try {
            Ai::chat($role)->chat(ChatRequest::make([['role' => 'user', 'content' => 'ok']], ['max_tokens' => 1, 'prompt_version' => 'rollback-probe'], 'registry.rollback'));
        } catch (Throwable $e) {
            $this->line($line);

            $this->error("  the first request to {$role} failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line($line.' · first request served by v'.$version.' after '.number_format(microtime(true) - $started, 1).' s');

        return self::SUCCESS;
    }
}
