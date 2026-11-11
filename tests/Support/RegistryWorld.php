<?php

namespace Tests\Support;

use App\Ai\Registry\Aliases;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\File;

// Chapter 44: a registry in a temporary directory: the alias file, the archive and the artifact files all live there, so no test touches storage/.
final class RegistryWorld
{
    public static string $directory = '';

    public static function boot(): string
    {
        self::$directory = sys_get_temp_dir().'/registry-world-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists(self::$directory);

        Aliases::useFile(self::$directory.'/aliases.json');

        config([
            'ai.registry.archive' => self::$directory.'/archive',
            'ai.registry.mirror' => self::$directory.'/mirror',
            'ai.registry.verify_base' => false,
            'ai.registry.signing.required' => false,
            'ai.providers.local-billing-ft.base_url' => 'http://billing-ft.test/v1',
        ]);

        return self::$directory;
    }

    public static function clean(): void
    {
        Aliases::useFile(null);

        File::deleteDirectory(self::$directory);
    }

    // Artifact files with the hashes the registry records when they are made.
    public static function artifacts(string $name = 'billing', int $version = 1): array
    {
        $directory = self::$directory."/{$name}-v{$version}";

        File::ensureDirectoryExists($directory);

        File::put($directory.'/adapters.safetensors', "adapter weights {$name} {$version}");

        File::put($directory.'/adapter_config.json', json_encode(['base_model_name_or_path' => 'meta-llama/Llama-3.1-8B-Instruct', 'r' => 16]));

        File::put($directory."/{$name}-v{$version}.Q4_K_M.gguf", "gguf bytes {$name} {$version}");

        return [
            'adapter' => 'sha256:'.hash_file('sha256', $directory.'/adapters.safetensors'),
            'gguf' => 'sha256:'.hash_file('sha256', $directory."/{$name}-v{$version}.Q4_K_M.gguf"),
            'quantization' => 'Q4_K_M',
            'files' => ['adapter' => $directory.'/adapters.safetensors', 'gguf' => $directory."/{$name}-v{$version}.Q4_K_M.gguf"],
        ];
    }

    public static function entry(string $name = 'billing', int $version = 1, string $stage = 'candidate', array $attributes = []): ModelRegistryEntry
    {
        return ModelRegistryEntry::query()->create($attributes + [
            'name' => $name,
            'version' => $version,
            'model' => "ledgerly-{$name}-ft-v{$version}",
            'base' => 'meta-llama/Llama-3.1-8B-Instruct',
            'base_revision' => 'a1b2c3d',
            'dataset' => 'billing-v1',
            'dataset_hash' => 'sha256:abc',
            'prompt_version' => 'ft1',
            'artifacts' => self::artifacts($name, $version),
            'tools' => ['llama.cpp' => 'b11776 (ead9f32)', 'peft' => '0.21.2'],
            'stage' => $stage,
            'history' => [['stage' => 'candidate', 'at' => now()->toIso8601String(), 'by' => 'test', 'reason' => 'exported and passed the template check']],
        ]);
    }

    // What eval:compare stores: one result per role, with the metrics the decision record's criteria read.
    public static function evaluation(array $tuned = [], array $base = []): array
    {
        return [[
            'at' => '2026-11-11',
            'set' => 'golden',
            'cases' => 20,
            'roles' => [
                ['role' => 'local-billing-ft', 'provider' => 'local-billing-ft', 'model' => 'm', 'metrics' => $tuned + ['golden_pass_rate' => 0.98, 'never_failures' => 0, 'injection_model_success_rate' => 0.05, 'tripwire_all_largest_drop' => 1.2, 'input_tokens_per_answer' => 900]],
                ['role' => 'local', 'provider' => 'local', 'model' => 'b', 'metrics' => $base + ['golden_pass_rate' => 0.9, 'never_failures' => 0, 'injection_model_success_rate' => 0.06, 'input_tokens_per_answer' => 1500]],
            ],
        ]];
    }
}
