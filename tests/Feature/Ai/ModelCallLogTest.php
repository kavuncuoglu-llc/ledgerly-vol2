<?php

use App\Ai\Events\ModelCalled;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

function modelCalled(array $overrides = []): ModelCalled
{
    return new ModelCalled(...array_merge([
        'feature' => 'support.reply',
        'provider' => 'ollama',
        'model' => 'llama3.1:8b',
        'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 5],
        'finishReason' => 'stop',
        'latencyMs' => 1840,
    ], $overrides));
}

it('has the model_calls columns the later chapters query', function () {
    expect(Schema::getColumnListing('model_calls'))->toBe([
        'id', 'feature', 'provider', 'model', 'prompt_tokens', 'completion_tokens',
        'finish_reason', 'latency_ms', 'cost_micros', 'conversation_id', 'created_at', 'updated_at',
        'prompt_version', 'repaired', 'cached_input_tokens', 'ttft_ms', 'context_breakdown', 'semantic_cache_hit', 'citation_errors', 'request', 'variant', 'trace_id', 'span_id', 'cache_write_tokens', 'job_uuid', 'attempt',
    ]);
});

it('records one row when a ModelCalled event is dispatched', function () {
    ModelCalled::dispatch('support.reply', 'ollama', 'llama3.1:8b', ['prompt_tokens' => 400, 'completion_tokens' => 5], 'stop', 1840);

    $call = ModelCall::sole();

    expect($call->feature)->toBe('support.reply')
        ->and($call->provider)->toBe('ollama')
        ->and($call->model)->toBe('llama3.1:8b')
        ->and($call->prompt_tokens)->toBe(400)
        ->and($call->completion_tokens)->toBe(5)
        ->and($call->finish_reason)->toBe('stop')
        ->and($call->latency_ms)->toBe(1840)
        ->and($call->conversation_id)->toBeNull();
});

it('costs a local model at nothing', function () {
    event(modelCalled());

    expect(ModelCall::sole()->cost_micros)->toBe(0);
});

it('prices a hosted call in micro-dollars from the rate table', function () {
    event(modelCalled([
        'provider' => 'illustrative',
        'model' => 'any-model',
        'usage' => ['prompt_tokens' => 2100, 'completion_tokens' => 150],
    ]));

    expect(ModelCall::sole()->cost_micros)->toBe(1350);
});

it('prefers a rate for the exact model over the provider wildcard', function () {
    config(['ai.rates.illustrative' => ['*' => ['in' => 0.50, 'out' => 2.00], 'model-1.5' => ['in' => 1.00, 'out' => 4.00]]]);

    event(modelCalled([
        'provider' => 'illustrative',
        'model' => 'model-1.5',
        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000],
    ]));

    expect(ModelCall::sole()->cost_micros)->toBe(5000);
});

it('costs an unknown provider at nothing instead of failing', function () {
    event(modelCalled(['provider' => 'somewhere-else']));

    expect(ModelCall::sole()->cost_micros)->toBe(0);
});

it('keeps the conversation id when there is one', function () {
    event(modelCalled(['conversationId' => 42]));

    expect(ModelCall::sole()->conversation_id)->toBe(42);
});

it('warns when a completion was truncated', function () {
    Log::spy();

    event(modelCalled(['finishReason' => 'length']));

    Log::shouldHaveReceived('warning')->once()->with('Completion truncated', Mockery::type('array'));
    expect(ModelCall::sole()->finish_reason)->toBe('length');
});

it('stays quiet when the completion finished normally', function () {
    Log::spy();

    event(modelCalled());

    Log::shouldNotHaveReceived('warning');
});
