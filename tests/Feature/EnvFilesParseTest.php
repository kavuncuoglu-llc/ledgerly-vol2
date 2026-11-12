<?php

use Dotenv\Dotenv;

// A value with a space and no quotes (AI_REVIEW_OWNER=platform team) makes Laravel refuse to boot with "The environment file is invalid"; only a clean checkout shows it, so it is a test.
it('has a .env.example that Laravel can parse', function (string $path) {
    expect(fn () => Dotenv::parse(file_get_contents(base_path($path))))->not->toThrow(Exception::class);
})->with(['.env.example', 'ops/compose/.env.example']);

it('keeps the two .env.example files identical', function () {
    expect(file_get_contents(base_path('ops/compose/.env.example')))->toBe(file_get_contents(base_path('.env.example')));
});
