<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// A whole Ollama in one Http::fake: version, library, show, ps and both chat endpoints. Override one thing to break one thing.
function fakeOllamaServer(array $overrides = []): void
{
    $settings = $overrides + [
        'version' => '0.40.14',
        'models' => ['ledgerly-support', 'nomic-embed-text', 'llama3.2-vision:11b'],
        'num_ctx' => 8192,
        'loaded' => [
            ['name' => 'ledgerly-support:latest', 'size' => 6_657_199_309, 'size_vram' => 6_657_199_309],
        ],
        'chat_ok' => true,
    ];

    Http::swap(new Factory);                                  // a second fake in one test must replace the first, not sit behind it

    Http::fake(function (Request $request) use ($settings) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/version' => Http::response(['version' => $settings['version']]),
            $path === '/api/tags' => Http::response(['models' => array_map(fn (string $name) => ['name' => str_contains($name, ':') ? $name : $name.':latest', 'size' => 1_000_000_000], $settings['models'])]),
            $path === '/api/show' => Http::response(fakeShow($request['model'], $settings['num_ctx'])),
            $path === '/api/ps' => Http::response(['models' => $settings['loaded']]),
            $path === '/api/chat' && $settings['chat_ok'] => Http::response(['model' => 'ledgerly-support', 'message' => ['role' => 'assistant', 'content' => 'ready'], 'done' => true, 'done_reason' => 'length', 'prompt_eval_count' => 21, 'eval_count' => 1, 'load_duration' => 1_000_000, 'prompt_eval_duration' => 100_000_000]),
            $path === '/v1/chat/completions' && $settings['chat_ok'] => Http::response(chatBody('ready', 'length', ['prompt_tokens' => 21, 'completion_tokens' => 1], 'ledgerly-support')),
            default => Http::response(['error' => 'boom'], 500),
        };
    });
}

function fakeShow(string $model, ?int $numCtx): array
{
    $isChat = str_starts_with($model, 'ledgerly-support');

    return [
        'details' => $isChat
            ? ['parent_model' => 'llama3.1:8b-instruct-q4_K_M', 'family' => 'llama', 'parameter_size' => '8.0B', 'quantization_level' => 'Q4_K_M']
            : ['parent_model' => '', 'family' => 'nomic-bert', 'parameter_size' => '137M', 'quantization_level' => 'F16'],
        'parameters' => $isChat && $numCtx !== null
            ? "num_ctx                        {$numCtx}\nnum_predict                    400\nstop                           \"<|eot_id|>\""
            : 'stop                           "<|eot_id|>"',
    ];
}
