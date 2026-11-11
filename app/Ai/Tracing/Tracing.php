<?php

namespace App\Ai\Tracing;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;

// Chapter 31, Topic 5: the one door to OpenTelemetry. With no SDK configured (OTEL_PHP_AUTOLOAD_ENABLED is false, which is the default) every call below is a no-op, so tests and a developer who has not started the collector pay nothing.
final class Tracing
{
    // The GenAI semantic conventions are experimental and their names have moved between releases: they are set from this class only, and the spec version they were checked against is pinned here.
    public const SEMCONV_VERSION = '1.37.0';

    public static function tracer(string $name = 'ledgerly.ai'): TracerInterface
    {
        return Globals::tracerProvider()->getTracer($name);
    }

    /** @return array{trace_id: ?string, span_id: ?string} the span that is current right now, or nulls when there is none */
    public static function ids(): array
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid()
            ? ['trace_id' => $context->getTraceId(), 'span_id' => $context->getSpanId()]
            : ['trace_id' => null, 'span_id' => null];
    }

    public static function enabled(): bool
    {
        return self::ids()['trace_id'] !== null || filter_var(getenv('OTEL_PHP_AUTOLOAD_ENABLED') ?: ($_ENV['OTEL_PHP_AUTOLOAD_ENABLED'] ?? false), FILTER_VALIDATE_BOOLEAN);
    }
}
