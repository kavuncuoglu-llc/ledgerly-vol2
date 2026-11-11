<?php

namespace App\Console\Commands;

use App\Ai\Registry\Registry;
use App\Models\ModelRegistryEntry;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RegistryAdd extends Command
{
    protected $signature = 'registry:add
        {model : The name the server knows the model by (ledgerly-billing-ft); with a version after it, the fine-tune\'s name instead (registry:add support 1)}
        {version? : With the form of Chapter 46, Figure 46-8 (registry:add support 1): the version this will be, checked against the next number}
        {--name=billing : The fine-tune this is a version of}
        {--model= : With a version: the name the server knows the model by (default <name>-v<version>, the adapter name vLLM chooses by)}
        {--dataset= : The dataset version it was trained on}
        {--run= : The MLflow run (mlflow:billing-mlx)}
        {--status=candidate : Only candidate: later stages are a registry:stage change (Chapter 44)}
        {--export= : The export record export_to_ollama.py wrote (training/models/<name>/export.json)}
        {--eval= : An eval:compare result to keep with the entry (eval:last, or a path)}
        {--base= : The base model, when there is no export record}
        {--base-revision= : The base\'s Hub revision}
        {--tokenizer= : The tokenizer repository for the template check}
        {--rows= : The training rows the template check renders (default: the first dataset\'s train.jsonl)}
        {--server=ollama : The server the template check renders through}
        {--no-check : Add it without the template check (the history says so)}';

    protected $description = 'Add an exported fine-tune to the model registry as a candidate, with its provenance and artifact hashes (Chapter 42)';

    public function handle(Registry $registry): int
    {
        if ($this->option('status') !== 'candidate') {
            $this->error('A new version is a candidate. Moving it on is registry:stage and registry:promote (Chapter 44).');

            return self::FAILURE;
        }

        if (! $this->option('dataset')) {
            $this->error('Name the dataset: --dataset=billing-v1.');

            return self::FAILURE;
        }

        try {
            [$model, $name] = $this->identity();

            $entry = $registry->add($model, [
                'name' => $name,
                'dataset' => (string) $this->option('dataset'),
                'run' => $this->option('run'),
                'export' => $this->option('export'),
                'evaluation' => $this->evaluation(),
                'base' => $this->option('base'),
                'base_revision' => $this->option('base-revision'),
                'tokenizer' => $this->option('tokenizer'),
                'rows' => $this->option('rows'),
                'server' => (string) $this->option('server'),
                'skip_check' => (bool) $this->option('no-check'),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("  {$entry->name} v{$entry->version} · {$entry->model} · candidate · dataset {$entry->dataset} {$entry->dataset_hash} · prompt {$entry->prompt_version}".($entry->training_run === null ? '' : " · run {$entry->training_run}"));

        return self::SUCCESS;
    }

    private function evaluation(): ?string
    {
        $eval = $this->option('eval');

        return $eval === 'eval:last'
            ? storage_path('eval/last-compare.json')
            : $eval;
    }

    // `registry:add ledgerly-billing-ft` is a server's model name, the fine-tune given by --name. `registry:add support 1` is the fine-tune's name and its version, and the model name is made from them.
    /** @return array{0: string, 1: string} */
    private function identity(): array
    {
        $version = $this->argument('version');

        if ($version === null) {
            return [(string) $this->argument('model'), (string) $this->option('name')];
        }

        $name = (string) $this->argument('model');

        $next = (int) ModelRegistryEntry::query()->where('name', $name)->max('version') + 1;

        if ((int) $version !== $next) {
            throw new InvalidArgumentException("The next version of {$name} is {$next}, not {$version}: versions are numbered by the registry.");
        }

        return [(string) ($this->option('model') ?: "{$name}-v{$next}"), $name];
    }
}
