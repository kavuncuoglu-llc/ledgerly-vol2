<?php

use App\Ai\Drivers\ProviderErrors;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;

it('rebuilds a response from what a package kept, so callers still read it', function () {
    $error = ProviderErrors::rebuilt(429, 'slow down', 7);

    expect($error)->toBeInstanceOf(RateLimited::class)
        ->and($error->response->status())->toBe(429)
        ->and($error->retryAfterSeconds())->toBe(7)
        ->and($error->response->json('error.message'))->toBe('slow down');
});

it('maps an overload and a too-large request', function () {
    expect(ProviderErrors::rebuilt(529, 'busy'))->toBeInstanceOf(ProviderUnavailable::class)
        ->and(ProviderErrors::rebuilt(413, 'too large'))->toBeInstanceOf(ContextTooLong::class);
});
