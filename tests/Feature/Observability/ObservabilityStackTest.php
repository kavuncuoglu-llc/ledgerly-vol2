<?php

use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Tracing\Tracing;
use App\Facades\Ai;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Otel;

// Chapter 31, Figure 31-9: the observability profile and what it enforces, as files.
it('runs a collector and Jaeger in the observability profile, with ports on localhost only and images pinned', function () {
    $compose = Yaml::parseFile(base_path('ops/compose/observability.yml'));

    foreach (['otel-collector', 'jaeger'] as $service) {
        expect($compose['services'][$service]['profiles'])->toBe(['observability'])
            ->and($compose['services'][$service]['image'])->not->toContain(':latest')->toMatch('/:[0-9]+\.[0-9]+\.[0-9]+\}$/');

        foreach ($compose['services'][$service]['ports'] ?? [] as $port) {
            expect($port)->toStartWith('127.0.0.1:');
        }
    }

    expect($compose['services']['jaeger']['ports'])->toContain('127.0.0.1:${JAEGER_PORT:-16686}:16686')
        ->and($compose['services']['otel-collector']['volumes'])->toContain('./otel-collector.yaml:/etc/otelcol-contrib/config.yaml:ro');
});

it('instruments the sidecar and the Python worker when the profile is on', function () {
    $compose = Yaml::parseFile(base_path('ops/compose/observability.yml'));

    expect($compose['services']['sidecar']['environment']['OTEL_EXPORTER_OTLP_ENDPOINT'])->toBe('http://otel-collector:4318')
        ->and($compose['services']['py-worker']['command'])->toContain('opentelemetry-instrument')
        ->and(file_get_contents(base_path('sidecar/Dockerfile')))->toContain('"opentelemetry-instrument", "uvicorn"')->toContain('OTEL_TRACES_EXPORTER=none')
        ->and(file_get_contents(base_path('sidecar/requirements.txt')))->toContain('opentelemetry-distro==')->toContain('opentelemetry-instrumentation-fastapi==');
});

it('makes the collector delete any attribute that names prompt or completion content, and batch', function () {
    $config = Yaml::parseFile(base_path('ops/compose/otel-collector.yaml'));

    $patterns = collect($config['processors']['attributes/strip-content']['actions']);

    expect($patterns->every(fn (array $action) => $action['action'] === 'delete'))->toBeTrue()
        ->and($config['service']['pipelines']['traces']['processors'])->toBe(['attributes/strip-content', 'batch'])
        ->and($config['exporters']['otlp/jaeger']['endpoint'])->toBe('jaeger:4317');

    foreach (['gen_ai.prompt.0.content', 'gen_ai.completion.0.content', 'gen_ai.input.messages', 'gen_ai.output.messages', 'ledgerly.content.question'] as $attribute) {
        expect($patterns->contains(fn (array $action) => preg_match('/'.$action['pattern'].'/', $attribute) === 1))->toBeTrue($attribute);
    }

    foreach (['gen_ai.request.model', 'gen_ai.usage.input_tokens', 'ledgerly.feature', 'ledgerly.model_call_id'] as $attribute) {
        expect($patterns->contains(fn (array $action) => preg_match('/'.$action['pattern'].'/', $attribute) === 1))->toBeFalse($attribute);
    }
});

it('adds trace and span ids to both audit tables', function () {
    foreach (['model_calls', 'tool_calls'] as $table) {
        expect(Schema::getColumnListing($table))->toContain('trace_id')->toContain('span_id');
    }
});

it('does nothing, and costs nothing, while tracing is off', function () {
    expect(config('ai'))->not->toBeNull()
        ->and(Globals::tracerProvider())->toBeInstanceOf(NoopTracerProvider::class)
        ->and(Tracing::ids())->toBe(['trace_id' => null, 'span_id' => null]);
});

describe('ai:sample --trace', function () {
    it('prints the answer and says tracing is off when it is', function () {
        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        [$user] = customerWithInvoice();

        [$code, $out] = artisanOutput('ai:sample', ['prompt' => 'Why did my March invoice go up?', '--trace' => true, '--as-user' => $user->id]);

        expect($code)->toBe(0)->and($out)->toContain('Fees start after 30 days.')->toContain('Tracing is off');
    });

    it('prints the trace id and a link to Jaeger when it is on', function () {
        $otel = Otel::start();

        try {
            config(['ai.tracing.jaeger_url' => 'http://localhost:16686']);

            Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

            [$user] = customerWithInvoice();

            [$code, $out] = artisanOutput('ai:sample', ['prompt' => 'Why did my March invoice go up?', '--trace' => true, '--as-user' => $user->id]);

            $root = $otel->spans('ai:sample')[0];

            expect($code)->toBe(0)
                ->and($out)->toContain("trace {$root->getTraceId()} · http://localhost:16686/trace/{$root->getTraceId()}")
                ->and(collect($otel->spans())->every(fn ($span) => $span->getTraceId() === $root->getTraceId()))->toBeTrue();       // the job and the model call are in the same trace
        } finally {
            $otel->detach();
        }
    });

    it('wants a question and a customer user', function () {
        [$code, $out] = artisanOutput('ai:sample', ['prompt' => 'x', '--trace' => true, '--as-user' => 999999]);

        expect($code)->toBe(1)->and($out)->toContain('--trace wants a question and a customer user');

        $staff = User::factory()->support()->create();

        expect(artisanOutput('ai:sample', ['prompt' => 'x', '--trace' => true, '--as-user' => $staff->id])[0])->toBe(1);
    });
});

it('keeps the driver at the bottom of the decorated chain', function () {
    expect(Ai::chat('local')->unwrap())->toBeInstanceOf(OpenAiCompatibleDriver::class);
});
