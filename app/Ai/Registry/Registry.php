<?php

namespace App\Ai\Registry;

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Training\Dataset;
use App\Models\ModelRegistryEntry;
use InvalidArgumentException;

// Chapter 42, Build, and Chapter 44, Topic 6: adding a version. A new version is a candidate, numbered after the last version of its name, with the provenance read from the dataset and the export record rather than typed: the dataset's hash is the one training read, the artifact hashes are the ones export_to_ollama.py computed.
final class Registry
{
    public function __construct(private TemplateGate $gate) {}

    /**
     * @param  array{name?: string, dataset: string, run?: ?string, export?: ?string, evaluation?: ?string, base?: ?string, base_revision?: ?string, tokenizer?: ?string, rows?: ?string, server?: string, skip_check?: bool, by?: string}  $options
     */
    public function add(string $model, array $options): ModelRegistryEntry
    {
        $export = $this->export($options['export'] ?? null);

        $dataset = Dataset::named($options['dataset']);

        if (! $dataset->exists('train.jsonl')) {
            throw new InvalidArgumentException("No dataset {$options['dataset']} in storage/datasets: the registry records the hash of the data a model was trained on.");
        }

        if (! ($options['skip_check'] ?? false)) {
            $result = $this->gate->check($model, $options['tokenizer'] ?? null, $options['server'] ?? 'ollama', $options['rows'] ?? null);

            if (! $result['passed']) {
                throw new InvalidArgumentException("{$model} failed its template check, so it is not a candidate:\n{$result['output']}");
            }
        }

        $name = $options['name'] ?? 'billing';

        $entry = ModelRegistryEntry::query()->create([
            'name' => $name,
            'version' => (int) ModelRegistryEntry::query()->where('name', $name)->max('version') + 1,
            'model' => $model,
            'base' => $options['base'] ?? $export['base'] ?? (string) config('ai.providers.local-billing-ft.fine_tuned.base'),
            'base_revision' => $options['base_revision'] ?? null,
            'dataset' => $options['dataset'],
            'dataset_hash' => $dataset->hash('train.jsonl'),
            'training_run' => $options['run'] ?? null,
            'prompt_version' => SupportPrompt::FINE_TUNED_VERSION,
            'artifacts' => $this->artifacts($export, $dataset),
            'evaluations' => $this->evaluations($options['evaluation'] ?? null),
            'stage' => 'candidate',
            'history' => [['stage' => 'candidate', 'at' => now()->toIso8601String(), 'by' => $options['by'] ?? 'registry:add', 'reason' => ($options['skip_check'] ?? false) ? 'added without the template check' : 'exported and passed the template check']],
        ]);

        return $entry;
    }

    private function export(?string $path): array
    {
        if ($path === null) {
            return [];
        }

        $absolute = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! is_file($absolute)) {
            throw new InvalidArgumentException("No export record at {$absolute}: export_to_ollama.py writes it next to the Modelfile.");
        }

        return json_decode((string) file_get_contents($absolute), true) ?? [];
    }

    private function artifacts(array $export, Dataset $dataset): array
    {
        return array_filter([
            ...($export['artifacts'] ?? []),
            'quantization' => $export['quantization'] ?? null,
            'backend' => $export['backend'] ?? null,
            'dataset_manifest' => $dataset->exists('manifest.json') ? $dataset->hash('manifest.json') : null,
        ], fn ($value) => $value !== null);
    }

    private function evaluations(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }

        $absolute = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! is_file($absolute)) {
            throw new InvalidArgumentException("No comparison at {$absolute}: run eval:compare first.");
        }

        $result = json_decode((string) file_get_contents($absolute), true);

        return [['at' => $result['ran_on'] ?? null, 'set' => $result['set'] ?? null, 'cases' => $result['cases'] ?? null, 'roles' => $result['results'] ?? []]];
    }
}
