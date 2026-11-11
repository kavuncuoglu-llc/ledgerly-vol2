<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Exceptions\CircuitOpen;
use App\Ai\Health\CircuitBreaker;
use Closure;
use Generator;
use Throwable;

// Retries (Chapter 8's policy) and, since Chapter 25, the circuit breaker: every attempt counts toward it, and once it is open nothing waits on the dead server.
final class RetryingModel extends ModelDecorator
{
    public function __construct(ChatModel $inner, private ?CircuitBreaker $breaker = null)
    {
        parent::__construct($inner);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return Retries::run(fn () => $this->attempt(fn () => $this->inner->chat($request)));
    }

    // Only the opening of a stream is retried. Once the first event has been handed to the caller a retry would repeat text they already saw; a stream that dies later is the caller's StreamTruncated.
    public function stream(ChatRequest $request): Generator
    {
        for ($attempt = 1; ; $attempt++) {
            $this->refuseWhenOpen();

            $stream = $this->inner->stream($request);

            try {
                $stream->current();                                               // the request is made here, on the first pull
            } catch (Throwable $e) {
                $this->recordFailure($e);

                if (! Retries::worthRetrying($e) || $attempt >= Retries::ATTEMPTS) {
                    throw $e;
                }

                Retries::pause($e, $attempt);

                continue;
            }

            $this->breaker()->recordSuccess($this->name());

            return yield from $stream;
        }
    }

    private function attempt(Closure $call): mixed
    {
        $this->refuseWhenOpen();

        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->recordFailure($e);

            throw $e;
        }

        $this->breaker()->recordSuccess($this->name());

        return $result;
    }

    private function refuseWhenOpen(): void
    {
        if (! $this->breaker()->isOpen($this->name())) {
            return;
        }

        throw new CircuitOpen($this->name(), $this->breaker()->openSeconds());     // the router catches this and falls back
    }

    private function recordFailure(Throwable $e): void
    {
        if (! CircuitBreaker::counts($e)) {
            return;
        }

        $this->breaker()->recordFailure($this->name(), class_basename($e));
    }

    private function breaker(): CircuitBreaker
    {
        return $this->breaker ??= app(CircuitBreaker::class);
    }
}
