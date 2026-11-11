<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// vLLM in one Http::fake, with the answers its documented endpoints give: /health and /metrics are public, /v1/* sits behind the API key (401 {"error": "Unauthorized"} without it).
// The metrics are the shape of vllm:cache_config_info, whose labels carry the number of KV blocks and their size: 13,280 blocks of 16 tokens is the 212,480-token line of Figure 24-8.
function fakeVllmServer(array $overrides = []): void
{
    $settings = $overrides + [
        'health' => 200,
        'key' => 'vllm-test-key',
        'model' => 'ledgerly-support',
        'max_model_len' => 8192,
        'blocks' => 13280,
        'block_size' => 16,
        'prefix_caching' => 'True',
        'metrics' => true,
        'chat_ok' => true,
        'unauthenticated' => false,
        'running' => 3,
        'waiting' => 0,
    ];

    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($settings) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $authorised = $settings['unauthenticated'] || $request->header('Authorization') === ['Bearer '.$settings['key']];

        if ($path === '/health') {
            return Http::response('', $settings['health']);
        }

        if ($path === '/metrics') {
            return $settings['metrics']
                ? Http::response(vllmMetrics($settings), 200, ['Content-Type' => 'text/plain'])
                : Http::response('Not Found', 404);
        }

        if (! $authorised) {
            return Http::response(['error' => 'Unauthorized'], 401);
        }

        return match ($path) {
            '/v1/models' => Http::response(['object' => 'list', 'data' => [[
                'id' => $settings['model'],
                'object' => 'model',
                'owned_by' => 'vllm',
                'root' => 'RedHatAI/Meta-Llama-3.1-8B-Instruct-FP8',
                'parent' => null,
                'max_model_len' => $settings['max_model_len'],
            ]]]),
            '/v1/chat/completions' => $settings['chat_ok']
                ? Http::response(chatBody('ready', 'length', ['prompt_tokens' => 21, 'completion_tokens' => 1], $settings['model']))
                : Http::response(['object' => 'error', 'message' => 'engine dead', 'type' => 'InternalServerError', 'code' => 500], 500),
            default => Http::response(['detail' => 'Not Found'], 404),
        };
    });
}

function vllmMetrics(array $settings): string
{
    return implode("\n", [
        '# HELP vllm:cache_config_info Information of the LLMEngine CacheConfig',
        '# TYPE vllm:cache_config_info gauge',
        sprintf('vllm:cache_config_info{block_size="%d",cache_dtype="auto",enable_prefix_caching="%s",gpu_memory_utilization="0.92",num_gpu_blocks="%d"} 1.0', $settings['block_size'], $settings['prefix_caching'], $settings['blocks']),
        '# TYPE vllm:num_requests_running gauge',
        sprintf('vllm:num_requests_running{model_name="%s"} %d.0', $settings['model'], $settings['running']),
        sprintf('vllm:num_requests_waiting{model_name="%s"} %d.0', $settings['model'], $settings['waiting']),
        '',
    ]);
}
