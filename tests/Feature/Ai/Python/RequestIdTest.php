<?php

use App\Ai\Sidecar\SidecarClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

it('gives every request a correlation ID and returns it in the response', function () {
    $response = $this->get('/up');

    $requestId = $response->headers->get('X-Request-Id');

    expect($requestId)->toMatch('/^[0-9a-f-]{36}$/');
});

it('keeps a well-formed ID the caller sent, and replaces a malformed one', function () {
    $kept = $this->get('/up', ['X-Request-Id' => 'caller-chosen-id-123']);
    $replaced = $this->get('/up', ['X-Request-Id' => "bad id\nwith newline"]);

    expect($kept->headers->get('X-Request-Id'))->toBe('caller-chosen-id-123')
        ->and($replaced->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('sends the correlation ID to the sidecar in X-Request-Id', function () {
    Http::fake(['sidecar.test/*' => Http::response(['scores' => [0.5]])]);
    Context::add('request_id', 'req-abcdef12');

    SidecarClient::fromConfig()->json('/rerank', ['question' => 'q', 'passages' => ['p']]);

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-Id', 'req-abcdef12'));
});

it('sends no header when there is no request, as in an Artisan command', function () {
    Http::fake(['sidecar.test/*' => Http::response(['scores' => [0.5]])]);

    SidecarClient::fromConfig()->json('/rerank', ['question' => 'q', 'passages' => ['p']]);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Request-Id'));
});
