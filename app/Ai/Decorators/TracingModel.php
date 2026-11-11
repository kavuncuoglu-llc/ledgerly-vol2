<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Exceptions\AiException;
use App\Ai\Tracing\Tracing;
use Generator;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

// Chapter 31, Topic 5: outermost in AiManager's chain, so every call through every provider (a fake in a test too) gets one span with the GenAI attributes and the app's own. No feature code knows.
// Retries are events on this span (Retries::pause adds them), the first streamed token is an event, and an error sets the status with a class name and the model_call_id: never the payload (Topic 2).
final class TracingModel extends ModelDecorator
{
    public function chat(ChatRequest $request): ChatResponse
    {
        $span = $this->start($request);

        $scope = $span->activate();

        try {
            $response = $this->inner->chat($request);

            $this->finish($span, $response);

            return $response;
        } catch (Throwable $e) {
            $this->fail($span, $e);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function stream(ChatRequest $request): Generator
    {
        $span = $this->start($request);

        $scope = $span->activate();

        try {
            $stream = $this->inner->stream($request);

            $first = false;

            foreach ($stream as $event) {
                if (! $first && $event instanceof Delta) {
                    $span->addEvent('first_token');                                // time to first token is the gap from the span's start

                    $first = true;
                }

                yield $event;
            }

            $response = $stream->getReturn();

            $this->finish($span, $response);

            return $response;
        } catch (Throwable $e) {
            $this->fail($span, $e);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function start(ChatRequest $request): SpanInterface
    {
        $model = $request->model ?? $this->unwrapModel();

        $span = Tracing::tracer()
            ->spanBuilder("chat {$model}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $span->setAttributes(array_filter([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => $model,
            'gen_ai.request.temperature' => $request->options['temperature'] ?? config("ai.profiles.{$request->profile}.temperature"),
            'gen_ai.request.max_tokens' => $request->options['max_tokens'] ?? null,
            'ledgerly.feature' => $request->feature,
            'ledgerly.prompt_version' => $request->meta['prompt_version'] ?? null,
            'ledgerly.provider' => $this->name(),
            'ledgerly.variant' => $request->meta['variant'] ?? null,
        ], fn ($value) => $value !== null));

        $traceId = $span->getContext()->getTraceId();

        if ($span->getContext()->isValid()) {
            Context::add('trace_id', $traceId);                                    // AiException::context() and the audit rows read it
        }

        return $span;
    }

    private function finish(SpanInterface $span, ChatResponse $response): void
    {
        $span->setAttributes(array_filter([
            'gen_ai.response.model' => $response->model,
            'gen_ai.usage.input_tokens' => $response->usage->promptTokens,
            'gen_ai.usage.output_tokens' => $response->usage->completionTokens,
            'gen_ai.response.finish_reasons' => [$response->finishReason->value],
            'ledgerly.model_call_id' => $response->callId,
        ], fn ($value) => $value !== null));
    }

    private function fail(SpanInterface $span, Throwable $e): void
    {
        $span->addEvent('exception', [                                             // not recordException(): that would copy any message, and a provider's error can echo a request
            'exception.type' => $e::class,
            'exception.message' => $e instanceof AiException ? $e->getMessage() : class_basename($e),
        ]);

        $span->setStatus(StatusCode::STATUS_ERROR, class_basename($e));
    }

    private function unwrapModel(): string
    {
        $driver = $this->unwrap();

        return method_exists($driver, 'model')
            ? (string) $driver->model()
            : $this->name();
    }
}
