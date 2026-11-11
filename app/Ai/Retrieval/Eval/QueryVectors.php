<?php

namespace App\Ai\Retrieval\Eval;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

// One Python process embeds every question with the named model (training/scripts/embed_queries.py): the Hub repo the worker serves, or a model directory under runs/embeddings that tune_embeddings.py wrote.
class QueryVectors
{
    /**
     * @param  list<string>  $questions
     * @return array<string, float[]>
     */
    public function embed(array $questions, string $model): array
    {
        $directory = sys_get_temp_dir().'/queries-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($directory);

        File::put($directory.'/questions.json', json_encode(array_values(array_unique($questions)), JSON_UNESCAPED_UNICODE));

        $result = Process::path(base_path('training'))->timeout(1800)->run([config('ai.registry.uv_binary', 'uv'), 'run', 'python', 'scripts/embed_queries.py', '--model', $model, '--questions', $directory.'/questions.json', '--out', $directory.'/vectors.json']);

        if ($result->failed()) {
            File::deleteDirectory($directory);

            throw new RuntimeException('embed_queries.py failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $vectors = json_decode(File::get($directory.'/vectors.json'), true);

        File::deleteDirectory($directory);

        return $vectors;
    }
}
