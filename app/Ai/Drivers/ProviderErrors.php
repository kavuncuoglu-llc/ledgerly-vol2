<?php

namespace App\Ai\Drivers;

use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\ContentFiltered;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

// Chapter 19, Topic 4, and Chapter 47: one table from a failed HTTP answer to the app's own exception types, shared by the raw driver and by every driver that wraps a package. A package that loses the response (Prism's rate-limit exception keeps only the retry-after seconds) is given a response rebuilt from what it kept, so a caller still reads `$e->response`.
final class ProviderErrors
{
    public static function from(Response $response): AiRequestFailed
    {
        $status = $response->status();

        $text = strtolower((string) ($response->json('error.message') ?? $response->json('error') ?? $response->body()));

        $code = strtolower((string) ($response->json('error.code') ?? ''));

        return match (true) {
            $status === 429 => new RateLimited($response),
            in_array($status, [408, 409, 425, 500, 502, 503, 504, 529], true) => new ProviderUnavailable($response),
            $code === 'content_filter' => new ContentFiltered($response),
            $status === 413 => new ContextTooLong($response),
            $status === 400 && (str_contains($text, 'context') || str_contains($text, 'too long') || str_contains($text, 'maximum')) => new ContextTooLong($response),
            default => new InvalidRequest($response),
        };
    }

    public static function rebuilt(int $status, string $message, ?int $retryAfter = null): AiRequestFailed
    {
        $headers = $retryAfter === null
            ? []
            : ['Retry-After' => (string) $retryAfter];

        $body = json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR);

        return self::from(new Response(new PsrResponse($status, $headers + ['Content-Type' => 'application/json'], $body)));
    }
}
