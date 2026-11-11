<?php

namespace App\Ai\Decorators;

use App\Ai\Exceptions\AiRequestFailed;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Sleep;
use Throwable;

// Chapter 8's policy, now written once for every driver: three attempts; 500 ms, 1 s (plus jitter) between them, or the server's Retry-After; only for failures that can pass.
final class Retries
{
    public const ATTEMPTS = 3;

    public static function run(Closure $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (Throwable $e) {
                if (! self::worthRetrying($e) || $attempt >= self::ATTEMPTS) {
                    throw $e;
                }

                self::pause($e, $attempt);
            }
        }
    }

    public static function pause(Throwable $e, int $attempt): void
    {
        Sleep::for(self::delayMs($e, $attempt))->milliseconds();
    }

    public static function worthRetrying(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof AiRequestFailed && $e->retryable();
    }

    private static function delayMs(Throwable $e, int $attempt): int
    {
        $retryAfter = $e instanceof AiRequestFailed
            ? $e->retryAfterSeconds()
            : null;

        if ($retryAfter !== null) {
            return $retryAfter * 1000;
        }

        return (int) (500 * (2 ** ($attempt - 1)) + random_int(0, 250));         // 500 ms, 1 s, 2 s + jitter
    }
}
