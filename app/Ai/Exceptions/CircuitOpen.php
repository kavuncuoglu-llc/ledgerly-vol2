<?php

namespace App\Ai\Exceptions;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

// Chapter 25, Topic 7: thrown instead of calling a server the breaker has marked down. It extends the same RequestException as every provider failure, so what already catches those (commands, the jobs' failed handlers) catches this, and it is never retried.
final class CircuitOpen extends AiRequestFailed
{
    public function __construct(public readonly string $provider, int $retryInSeconds)
    {
        parent::__construct(new Response(new PsrResponse(503, ['Retry-After' => (string) $retryInSeconds], json_encode(['error' => "{$provider} circuit open"]))));
    }

    public function retryable(): bool
    {
        return false;
    }
}
