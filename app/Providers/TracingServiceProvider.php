<?php

namespace App\Providers;

use App\Ai\Tracing\Tracing;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\SdkAutoloader;

// Chapter 31, Topic 6: queued jobs carry the trace. The payload gets an `otel` field when the job is pushed; when a worker starts the job, its span is a child of the span that queued it, seconds and a process later.
// The listing in the book injects with Queue::createPayloadUsing and extracts in a JobProcessing listener; the auto-instrumentation package can do the same once ext-opentelemetry is installed, so check what the version you pin does before you run both.
class TracingServiceProvider extends ServiceProvider
{
    /** @var array<int, array{0: SpanInterface, 1: ScopeInterface}> */
    private array $running = [];

    // The SDK normally starts when Composer's autoloader loads, which is before Laravel has read .env: a host-run php artisan would never see OTEL_PHP_AUTOLOAD_ENABLED from the file. Starting it here, once the
    // environment is loaded, makes .env work for the app, Horizon and the commands alike (a real environment variable, as in Compose, works either way). With the variable false this does nothing.
    public function register(): void
    {
        SdkAutoloader::autoload();
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(function () {
            $carrier = [];

            TraceContextPropagator::getInstance()->inject($carrier);              // adds traceparent (and tracestate)

            return ['otel' => $carrier];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $parent = TraceContextPropagator::getInstance()->extract($event->job->payload()['otel'] ?? []);

            $span = Tracing::tracer('ledgerly.queue')
                ->spanBuilder('job '.$event->job->resolveName())
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($parent)
                ->startSpan();

            $this->running[spl_object_id($event->job)] = [$span, $span->activate()];     // ended in JobProcessed / JobFailed

            $traceId = $span->getContext()->getTraceId();

            if ($span->getContext()->isValid()) {
                Context::add('trace_id', $traceId);
            }
        });

        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $this->end($event->job));

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->running[spl_object_id($event->job)][0]?->setStatus(StatusCode::STATUS_ERROR, class_basename($event->exception));      // the class, never the message

            $this->end($event->job);
        });
    }

    private function end(object $job): void
    {
        [$span, $scope] = $this->running[spl_object_id($job)] ?? [null, null];

        unset($this->running[spl_object_id($job)]);

        $scope?->detach();
        $span?->end();
    }
}
