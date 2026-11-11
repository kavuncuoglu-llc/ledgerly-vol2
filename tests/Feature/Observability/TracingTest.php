<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Decorators\TracingModel;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Exceptions\AiException;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Python\PythonTasks;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Streaming\ReplyStarter;
use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Facades\Ai;
use App\Jobs\AnswerQuestion;
use App\Models\ModelCall;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Tests\Support\Otel;

// Chapter 31: the AI layer's own spans, joined to the audit rows by trace ID and span ID. The SDK is an in-memory one; no collector, no Jaeger.
beforeEach(function () {
    Sleep::fake();

    $this->otel = Otel::start();

    [$this->user, $this->invoice, $this->customer] = customerWithInvoice();
});

afterEach(fn () => $this->otel->detach());

function chatRequest(string $feature = 'support.reply'): ChatRequest
{
    return ChatRequest::make([['role' => 'user', 'content' => 'How is the late fee calculated?']], ['profile' => 'deterministic', 'max_tokens' => 300, 'prompt_version' => SupportPrompt::VERSION], $feature);
}

describe('model-call spans (Topic 5)', function () {
    it('gives every call through every provider one span with the GenAI attributes and the app\'s own, fakes included', function () {
        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        Ai::chat('local')->chat(chatRequest());

        $spans = $this->otel->spans('chat ');

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->getKind())->toBe(SpanKind::KIND_CLIENT);

        $attributes = $this->otel->attributes('chat ');

        expect($attributes)->toMatchArray([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'fake',
            'gen_ai.request.temperature' => 0,
            'gen_ai.request.max_tokens' => 300,
            'gen_ai.response.finish_reasons' => ['stop'],
            'ledgerly.feature' => 'support.reply',
            'ledgerly.prompt_version' => SupportPrompt::VERSION,
            'ledgerly.provider' => 'local',
        ])->and($attributes['gen_ai.usage.output_tokens'])->toBeInt();
    });

    it('carries the model_calls id, and the audit row carries the trace and span ids of the same span', function () {
        Http::fake(['*/chat/completions' => chatReply('Fees start after 30 days.')]);

        $response = Ai::chat('local')->chat(chatRequest());

        $span = $this->otel->spans('chat ')[0];

        $row = ModelCall::sole();

        expect($response->callId)->toBe($row->id)
            ->and($this->otel->attributes('chat ')['ledgerly.model_call_id'])->toBe($row->id)
            ->and($row->trace_id)->toBe($span->getTraceId())
            ->and($row->span_id)->toBe($span->getSpanId())
            ->and(Context::get('trace_id'))->toBe($span->getTraceId());
    });

    it('records a retry as an event on the span, with the attempt and the error class, not as a span of its own', function () {
        Http::fake(['*/chat/completions' => Http::sequence()->push('busy', 503)->push('busy', 503)->push(chatBody('Fees start after 30 days.'))]);

        Ai::chat('local')->chat(chatRequest());

        $spans = $this->otel->spans('chat ');

        $events = collect($spans[0]->getEvents())->filter(fn ($event) => $event->getName() === 'retry');

        expect($spans)->toHaveCount(1)
            ->and($events)->toHaveCount(2)
            ->and($events->first()->getAttributes()->get('attempt'))->toBe(1)
            ->and($events->first()->getAttributes()->get('error.type'))->not->toBeNull()
            ->and(ModelCall::count())->toBe(1);                                      // one logged call, however many attempts
    });

    it('adds a first_token event to a streamed call', function () {
        Http::fake(['*/chat/completions' => streamReply(['Fees ', 'start.'])]);

        $stream = Ai::chat('local')->stream(chatRequest());

        foreach ($stream as $ignored) {
            // read it to the end
        }

        $events = collect($this->otel->spans('chat ')[0]->getEvents())->map(fn ($event) => $event->getName());

        expect($events->all())->toContain('first_token')
            ->and($this->otel->attributes('chat ')['gen_ai.usage.output_tokens'])->toBeInt();
    });

    it('sets the span\'s status to an error with the class name when the call fails, and records no payload', function () {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'SECRET CUSTOMER TEXT echoed by the provider']], 400)]);

        try {
            Ai::chat('local')->chat(chatRequest());
        } catch (Throwable) {
            // the test is about the span
        }

        $span = $this->otel->spans('chat ')[0];

        $event = collect($span->getEvents())->first(fn ($event) => $event->getName() === 'exception');

        expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
            ->and($span->getStatus()->getDescription())->toBe('InvalidRequest')
            ->and($event->getAttributes()->get('exception.type'))->toContain('InvalidRequest')
            ->and($event->getAttributes()->get('exception.message'))->toBe('InvalidRequest')
            ->and(json_encode($span->getAttributes()->toArray()))->not->toContain('SECRET');
    });

    it('never puts the question or the answer on the span', function () {
        Ai::fake()->for('support.reply')->respondWith('A private answer about INV-4471.');

        Ai::chat('local')->chat(chatRequest());

        $keys = array_keys($this->otel->attributes('chat '));

        expect(collect($keys)->filter(fn (string $key) => preg_match('/^(gen_ai\.(prompt|completion|input|output)\.|ledgerly\.content\.)/', $key) === 1))->toBeEmpty()
            ->and(json_encode($this->otel->attributes('chat ')))->not->toContain('INV-4471');
    });

    it('is the outermost decorator, so usage logging happens inside its span', function () {
        expect(Ai::chat('local'))->toBeInstanceOf(TracingModel::class)
            ->and(Ai::chat('local')->unwrap())->toBeInstanceOf(OpenAiCompatibleDriver::class);
    });
});

describe('tool spans (Topic 5)', function () {
    it('gives each tool execution a span with the tool\'s name and the gate\'s decision, and the audit row its ids', function () {
        Ai::fake()->for('support.reply')
            ->respondWithToolCall('lookup_invoice', ['invoice_number' => 'INV-4471', 'month' => null])
            ->respondWith('Invoice INV-4471 is overdue.');

        app(ToolRunner::class)->run([['role' => 'user', 'content' => 'status of INV-4471?']], new ToolContext($this->user), [], 'support.reply');

        $span = $this->otel->spans('execute_tool ')[0];

        $attributes = $span->getAttributes()->toArray();

        $row = ToolCall::sole();

        expect($span->getName())->toBe('execute_tool lookup_invoice')
            ->and($attributes)->toMatchArray(['gen_ai.operation.name' => 'execute_tool', 'gen_ai.tool.name' => 'lookup_invoice', 'ledgerly.tool.decision' => 'allowed'])
            ->and(json_encode($attributes))->not->toContain('INV-4471')
            ->and($row->trace_id)->toBe($span->getTraceId())
            ->and($row->span_id)->toBe($span->getSpanId())
            ->and($this->otel->spans('chat '))->toHaveCount(2);                      // model, tool, model: the shape Topic 4 draws
    });

    it('records a denial on the span too', function () {
        $viewer = User::factory()->owner($this->customer)->create(['role' => 'viewer']);

        Ai::fake()->for('support.reply')
            ->respondWithToolCall('open_ticket', ['summary' => 'Refund all of my invoices.', 'invoice_number' => null])
            ->respondWith('I cannot do that.');

        app(ToolRunner::class)->run([['role' => 'user', 'content' => 'refund me']], new ToolContext($viewer), [], 'support.reply');

        expect($this->otel->attributes('execute_tool ')['ledgerly.tool.decision'])->toBe('denied');
    });

    it('spans an unknown tool the model made up', function () {
        Ai::fake()->for('support.reply')->respondWithToolCall('delete_everything', [])->respondWith('Sorry.');

        app(ToolRunner::class)->run([['role' => 'user', 'content' => 'x']], new ToolContext($this->user), [], 'support.reply');

        expect($this->otel->attributes('execute_tool delete_everything'))->toMatchArray(['ledgerly.tool.decision' => 'denied'])
            ->and(ToolCall::sole()->trace_id)->not->toBeNull();
    });
});

describe('carrying the trace across boundaries (Topic 6)', function () {
    it('makes a queued job\'s span a child of the span that queued it', function () {
        [$root, $scope] = Otel::root('POST /support/ask');

        Ai::fake()->for('support.reply')->respondWith('Fees start after 30 days.');

        test()->actingAs($this->user);

        app(ReplyStarter::class)->start($this->user, 'How is the late fee calculated?');

        $scope->detach();
        $root->end();

        $job = $this->otel->spans('job ')[0];

        expect($job->getName())->toBe('job '.AnswerQuestion::class)
            ->and($job->getTraceId())->toBe($root->getContext()->getTraceId())
            ->and($job->getParentSpanId())->toBe($root->getContext()->getSpanId())
            ->and($job->getKind())->toBe(SpanKind::KIND_CONSUMER);

        $chat = $this->otel->spans('chat ')[0];

        expect($chat->getTraceId())->toBe($root->getContext()->getTraceId())
            ->and($chat->getParentSpanId())->toBe($job->getSpanId());
    });

    it('puts an otel field with a traceparent in every queued job payload', function () {
        [$root, $scope] = Otel::root();

        $payloads = [];

        Event::listen(JobProcessing::class, function ($event) use (&$payloads) {
            $payloads[] = $event->job->payload();
        });

        Ai::fake()->for('support.reply')->respondWith('x');

        test()->actingAs($this->user);

        app(ReplyStarter::class)->start($this->user, 'Hi');

        $scope->detach();
        $root->end();

        expect($payloads[0]['otel']['traceparent'])->toStartWith('00-'.$root->getContext()->getTraceId().'-');
    });

    it('writes a traceparent field beside the task in a stream message, only when there is a trace', function () {
        $streams = fakeStreams();

        $id = chunkRow()->id;

        PythonTasks::embed([$id], 'nomic-ai/nomic-embed-text-v1.5');

        expect($streams->entries['py:tasks:embed'][0]['fields'])->not->toHaveKey('traceparent');

        [$root, $scope] = Otel::root();

        PythonTasks::embed([$id], 'nomic-ai/nomic-embed-text-v1.5');

        $scope->detach();
        $root->end();

        $fields = $streams->entries['py:tasks:embed'][1]['fields'];

        expect($fields['traceparent'])->toMatch('/^00-'.$root->getContext()->getTraceId().'-[0-9a-f]{16}-0[01]$/')
            ->and(json_decode($fields['task'], true))->not->toHaveKey('traceparent');                // the contract stays closed
    });

    it('sends the sidecar a traceparent header and nobody else', function () {
        Http::fake(['*' => Http::response(['ok' => true])]);

        [$root, $scope] = Otel::root();

        app(SidecarClient::class)->json('/rerank', ['question' => 'q', 'passages' => ['p']]);

        $scope->detach();
        $root->end();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sidecar.test')
            && str_starts_with($request->header('traceparent')[0] ?? '', '00-'.$root->getContext()->getTraceId()));
    });

    it('sends a model server no trace header', function () {
        Http::fake(['*/chat/completions' => chatReply('x')]);

        [$root, $scope] = Otel::root();

        Ai::chat('local')->chat(chatRequest());

        $scope->detach();
        $root->end();

        Http::assertSent(fn ($request) => ! $request->hasHeader('traceparent'));
    });
});

describe('what exceptions carry (Topic 2)', function () {
    it('holds a reason and the model_calls id, and offers the same two ids to the logger', function () {
        $exception = new class('The reply was cut off', 88213) extends AiException {};

        Context::add('trace_id', 'abc123');

        expect($exception->getMessage())->toBe('The reply was cut off (model_call 88213)')
            ->and($exception->context())->toBe(['model_call_id' => 88213, 'trace_id' => 'abc123']);
    });

    it('names the fields that failed validation, never what the model wrote', function () {
        $errors = new MessageBag(['total' => ['The total 1204.50 does not equal subtotal plus tax for Northgate Ltd.']]);

        $exception = new StructuredOutputFailed(InvoiceData::class, [['raw' => 'SECRET MODEL OUTPUT']], $errors);

        expect($exception)->toBeInstanceOf(AiException::class)
            ->and($exception->getMessage())->toBe('InvoiceData still failed validation after 1 attempts (fields: total)')
            ->and($exception->getMessage())->not->toContain('Northgate')
            ->and($exception->attempts)->toHaveCount(1);                              // the caller that handles it still has them
    });
});
