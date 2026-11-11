<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use App\Ai\Registry\ServeFineTune;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ServeFineTuneCommand extends Command
{
    protected $signature = 'serve:fine-tune
        {name : The fine-tune (billing)}
        {version : The version}
        {--ollama= : The Ollama server to put the merged GGUF on (a name in config ai.registry.servers)}
        {--vllm= : The vLLM server to load the adapter on, beside its base}
        {--adapter-name= : The name requests choose the adapter by (default: <name>-v<version>)}';

    protected $description = 'Serve a registered version: the merged GGUF on Ollama, the adapter on vLLM, after the startup checks (Chapter 44, Topics 4, 8 and 9)';

    public function handle(Registry $registry, ServeFineTune $serve): int
    {
        try {
            $lines = $serve->serve($registry->find((string) $this->argument('name'), (int) $this->argument('version')), $this->option('ollama'), $this->option('vllm'), $this->option('adapter-name'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($lines as $line) {
            $this->line("  {$line}");
        }

        $this->line('  next: php artisan ai:template-check <model> --server=ollama|vllm');

        return self::SUCCESS;
    }
}
