<?php

namespace App\Ai\Testing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Replays an exchange that `php artisan ai:record` saved, under the real driver, so a driver's normalisation is tested against actual provider output without a network.
final class FakeHttp
{
    public static function path(string $driver, string $fixture): string
    {
        return base_path("storage/fixtures/{$driver}/{$fixture}.json");
    }

    public static function load(string $driver, string $fixture): array
    {
        $path = self::path($driver, $fixture);

        if (! is_file($path)) {
            throw new RuntimeException("No recorded fixture at {$path}. Record one with `php artisan ai:record`.");
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function replay(string $driver, string $fixture, string $endpoint = '*/chat/completions'): void
    {
        $recorded = self::load($driver, $fixture);

        $response = $recorded['response'];

        Http::fake([$endpoint => Http::response($response['body'], $response['status'], $response['headers'] ?? [])]);
    }
}
