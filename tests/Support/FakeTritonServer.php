<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Triton behind the ops/triton/proxy nginx, in one Http::fake: everything needs the bearer key (the proxy's job), model control answers 403, and the KServe and OpenAI paths answer the way Triton's documented endpoints do.
function fakeTritonServer(array $overrides = []): void
{
    $settings = $overrides + [
        'ready' => 200,
        'key' => 'triton-test-key',
        'models' => ['chat', 'embedder', 'reranker', 'support_pipeline'],
        'chat_ok' => true,
        'unauthenticated' => false,
        'model_control' => 403,
        'metrics' => true,
    ];

    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($settings) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $authorised = $settings['unauthenticated'] || $request->header('Authorization') === ['Bearer '.$settings['key']];

        if (! $authorised) {
            return Http::response('', 401);
        }

        if ($path === '/v2/repository/index') {
            return Http::response('', $settings['model_control']);
        }

        if ($path === '/v2/health/ready') {
            return Http::response('', $settings['ready']);
        }

        if (preg_match('#^/v2/models/([^/]+)/ready$#', $path, $match) === 1) {
            return Http::response('', in_array($match[1], $settings['models'], true) ? 200 : 400);
        }

        if ($path === '/metrics') {
            return $settings['metrics']
                ? Http::response(tritonMetrics(), 200, ['Content-Type' => 'text/plain'])
                : Http::response('', 404);
        }

        if ($path === '/v1/chat/completions') {
            return $settings['chat_ok']
                ? Http::response(chatBody('ready', 'length', ['prompt_tokens' => 21, 'completion_tokens' => 1], 'chat'))
                : Http::response(['error' => 'engine dead'], 500);
        }

        return Http::response('', 404);
    });
}

// Figure 25-5: 48,213 reranker requests in 3,102 executions is about 15.5 per batch.
function tritonMetrics(): string
{
    return implode("\n", [
        '# TYPE nv_inference_request_success counter',
        'nv_inference_request_success{model="reranker",version="1"} 48213',
        'nv_inference_exec_count{model="reranker",version="1"} 3102',
        'nv_inference_request_success{model="embedder",version="1"} 100',
        'nv_inference_exec_count{model="embedder",version="1"} 100',
        'nv_gpu_utilization{gpu_uuid="GPU-1"} 0.71',
        '',
    ]);
}
