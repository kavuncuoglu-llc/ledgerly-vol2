<?php

use App\Ai\Local\OllamaNative;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('derives the native root from the OpenAI-compatible base url', function (string $baseUrl, string $root) {
    expect((new OllamaNative($baseUrl))->rootUrl())->toBe($root);
})->with([
    'with /v1' => ['http://localhost:11434/v1', 'http://localhost:11434'],
    'with trailing slash' => ['http://localhost:11434/v1/', 'http://localhost:11434'],
    'already the root' => ['http://localhost:11434', 'http://localhost:11434'],
]);

it('posts a non-streaming chat to /api/chat and passes options through', function () {
    Http::fake(['*' => Http::response(['message' => ['content' => 'ok']])]);

    $body = (new OllamaNative('http://localhost:11434/v1'))
        ->chat('llama3.1:8b', [['role' => 'user', 'content' => 'Hi']], ['num_ctx' => 8192]);

    expect($body['message']['content'])->toBe('ok');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:11434/api/chat'
        && $request['stream'] === false
        && $request['options'] === ['num_ctx' => 8192]);
});
