<?php

namespace App\Ai\Registry;

use Illuminate\Support\Facades\Artisan;

// Chapter 42, Topic 7: "a version is a candidate when it has been exported and passed its template check". The check is ai:template-check against the server the model is served from.
class TemplateGate
{
    /** @return array{passed: bool, output: string} */
    public function check(string $model, ?string $tokenizer = null, string $server = 'ollama', ?string $rows = null): array
    {
        $options = array_filter(['model' => $model, '--against' => 'training', '--server' => $server, '--tokenizer' => $tokenizer, '--rows' => $rows]);

        $code = Artisan::call('ai:template-check', $options);

        return ['passed' => $code === 0, 'output' => trim(Artisan::output())];
    }
}
