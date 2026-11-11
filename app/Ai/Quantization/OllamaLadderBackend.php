<?php

namespace App\Ai\Quantization;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

// The real backend: training/scripts/quantize.py (the pinned llama.cpp's llama-imatrix and llama-quantize) for the levels, and a native Ollama (`ollama create`, then /api/chat at temperature 0) to run them.
class OllamaLadderBackend implements LadderBackend
{
    public function quantize(string $f16, array $levels, ?string $calibration, string $directory): array
    {
        $command = [config('ai.registry.uv_binary', 'uv'), 'run', 'python', 'scripts/quantize.py', $f16, '--levels', implode(',', $levels), '--out', $directory];

        if ($calibration !== null) {
            $command = [...$command, '--calibration', $calibration];
        }

        $result = Process::path(base_path('training'))->timeout(7200)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('quantize.py failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $manifest = json_decode((string) File::get($directory.'/levels.json'), true);

        $built = [];

        foreach ($manifest['levels'] as $level => $entry) {
            $built[$level] = ['path' => $entry['path'], 'bytes' => (int) $entry['bytes']];
        }

        return $built;
    }

    public function load(string $name, string $gguf): void
    {
        $directory = sys_get_temp_dir().'/ladder-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($directory);

        File::put($directory.'/Modelfile', "FROM {$gguf}\nPARAMETER num_ctx 4096\nPARAMETER temperature 0\n");

        $result = Process::timeout(1800)->run([config('ai.registry.ollama_binary', 'ollama'), 'create', $name, '-f', $directory.'/Modelfile']);

        File::deleteDirectory($directory);

        if ($result->failed()) {
            throw new RuntimeException("ollama create {$name} failed: ".trim($result->errorOutput()));
        }
    }

    public function unload(string $name): void
    {
        Process::run([config('ai.registry.ollama_binary', 'ollama'), 'rm', $name]);
    }

    public function chat(string $name, array $messages): array
    {
        $response = Http::timeout(300)->post(rtrim((string) config('ai.registry.servers.local.url'), '/').'/api/chat', [
            'model' => $name,
            'messages' => $this->forOllama($messages),
            'stream' => false,
            'options' => ['temperature' => 0, 'seed' => 7, 'num_predict' => 256],
        ])->throw()->json();

        $decode = ($response['eval_duration'] ?? 0) / 1e9;

        return [
            'content' => (string) ($response['message']['content'] ?? ''),
            'ttft_seconds' => (($response['prompt_eval_duration'] ?? 0) / 1e9),
            'decode_tps' => $decode > 0
                ? ($response['eval_count'] ?? 0) / $decode
                : 0.0,
        ];
    }

    // Training rows carry a tool call's arguments as a JSON string; Ollama's native API wants an object (what ai:template-check does too).
    private function forOllama(array $messages): array
    {
        return array_map(function (array $message) {
            foreach ($message['tool_calls'] ?? [] as $index => $call) {
                if (is_string($call['function']['arguments'] ?? null)) {
                    $message['tool_calls'][$index]['function']['arguments'] = json_decode($call['function']['arguments'], true) ?? [];
                }
            }

            return $message;
        }, $messages);
    }
}
