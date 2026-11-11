<?php

namespace Tests\Support;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

// An in-memory OpenTelemetry SDK for a test: every span the code under test starts lands in $exporter, and nothing leaves the process. Call detach() in afterEach.
final class Otel
{
    private function __construct(public readonly InMemoryExporter $exporter, private ScopeInterface $scope) {}

    public static function start(): self
    {
        $exporter = new InMemoryExporter;

        $scope = Sdk::builder()
            ->setTracerProvider(new TracerProvider(new SimpleSpanProcessor($exporter)))
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();

        return new self($exporter, $scope);
    }

    public function detach(): void
    {
        $this->scope->detach();
    }

    /** @return list<ImmutableSpan> */
    public function spans(?string $name = null): array
    {
        $spans = $this->exporter->getSpans();

        return array_values($name === null
            ? $spans
            : array_filter($spans, fn ($span) => str_starts_with($span->getName(), $name)));
    }

    public function attributes(string $spanName): array
    {
        return $this->spans($spanName)[0]->getAttributes()->toArray();
    }

    public static function root(string $name = 'test root'): array
    {
        $span = Globals::tracerProvider()->getTracer('test')->spanBuilder($name)->startSpan();

        return [$span, $span->activate()];
    }
}
