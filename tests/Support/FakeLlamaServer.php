<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// llama-server in one Http::fake, with the answers a b11829 build gave (checked against a real one): /health public, everything else behind the key.
function fakeLlamaServer(array $overrides = []): void
{
    $settings = $overrides + [
        'health' => 200,
        'key' => 'llama-test-key',
        'slots' => 4,
        'slot_ctx' => 8192,
        'metrics' => true,
        'chat_ok' => true,
        'unauthenticated' => false,
    ];

    Http::swap(new Factory);                                  // a second fake in one test must replace the first, not sit behind it

    Http::fake(function (Request $request) use ($settings) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $authorised = $settings['unauthenticated'] || $request->header('Authorization') === ['Bearer '.$settings['key']];

        if ($path === '/health') {
            return Http::response($settings['health'] === 200 ? ['status' => 'ok'] : ['error' => ['code' => 503, 'message' => 'Loading model']], $settings['health']);
        }

        if (! $authorised) {
            return Http::response(['error' => ['code' => 401, 'message' => 'Invalid API Key', 'type' => 'authentication_error']], 401);
        }

        return match ($path) {
            '/props' => Http::response(['total_slots' => $settings['slots'], 'build_info' => 'b11829-184f653', 'endpoint_slots' => true]),
            '/slots' => $settings['slots'] === 0
                ? Http::response(['error' => ['code' => 501, 'message' => 'This server does not support slots endpoint.']], 501)
                : Http::response(array_map(fn (int $id) => ['id' => $id, 'n_ctx' => $settings['slot_ctx'], 'is_processing' => false], range(0, $settings['slots'] - 1))),
            '/metrics' => $settings['metrics']
                ? Http::response("# TYPE llamacpp:prompt_tokens_total counter\nllamacpp:prompt_tokens_total 0\nllamacpp:kv_cache_usage_ratio 0.25\n", 200, ['Content-Type' => 'text/plain'])
                : Http::response(['error' => ['code' => 501, 'message' => 'This server does not support metrics endpoint.']], 501),
            '/tokenize' => Http::response(['tokens' => [36120, 674, 20213, 16, 374, 83893, 555, 220, 2919, 13]]),
            '/v1/chat/completions' => $settings['chat_ok']
                ? Http::response(chatBody('ready', 'length', ['prompt_tokens' => 21, 'completion_tokens' => 1]))
                : Http::response(['error' => ['code' => 500, 'message' => 'boom']], 500),
            default => Http::response(['error' => ['code' => 404, 'message' => 'File Not Found']], 404),
        };
    });
}
