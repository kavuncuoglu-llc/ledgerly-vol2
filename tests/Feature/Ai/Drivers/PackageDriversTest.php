<?php

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Decorators\BudgetedModel;
use App\Ai\Decorators\ModelDecorator;
use App\Ai\Decorators\RedactingModel;
use App\Ai\Decorators\TracingModel;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\AiSdkDriver;
use App\Ai\Drivers\PrismDriver;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Testing\FakeHttp;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Facades\Ai;
use App\Models\ModelCall;
use App\Models\ToolCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// Chapter 47, Topic 8: a package goes below ModelClient, as one more driver, so every control applies to it. These tests are the claim, made checkable: the same chain around all three drivers, the same ledger row, the same tool loop, and nothing stored by the package.

function chainOf(object $model): array
{
    $classes = [];

    while ($model instanceof ModelDecorator) {
        $classes[] = $model::class;

        $model = (fn () => $this->inner)->call($model);
    }

    $classes[] = $model::class;

    return $classes;
}

function throughRole(string $role, string $feature = 'support.reply'): void
{
    config(['ai.features' => array_merge(config('ai.features'), [$feature => array_merge(config('ai.features')[$feature] ?? [], ['provider' => $role])])]);

    app(AiManager::class)->forget();
}

beforeEach(function () {
    Http::preventStrayRequests();
});

describe('the roles', function () {
    it('are the same server through three drivers', function () {
        $roles = collect(['local', 'local-prism', 'local-ai-sdk'])->map(fn (string $role) => config('ai.providers')[$role]);

        expect($roles->pluck('driver')->all())->toBe(['openai-compatible', 'prism', 'ai-sdk'])
            ->and($roles->pluck('base_url')->unique())->toHaveCount(1)
            ->and($roles->pluck('model')->unique())->toHaveCount(1);
    });

    it('resolve to the package drivers inside the full decorator chain', function (string $role, string $driver) {
        config(['ai.budgets.enabled' => true]);

        app(AiManager::class)->forget();

        $chain = chainOf(Ai::chat($role));

        expect($chain[0])->toBe(TracingModel::class)
            ->and($chain)->toContain(BudgetedModel::class, RedactingModel::class, UsageLoggingModel::class)
            ->and(end($chain))->toBe($driver);
    })->with([
        'prism' => ['local-prism', PrismDriver::class],
        'ai-sdk' => ['local-ai-sdk', AiSdkDriver::class],
    ]);

    it('can answer chat but leave embeddings to the raw driver', function () {
        config(['ai.providers.embed-prism' => array_merge(config('ai.providers.local-prism'), ['driver' => 'prism'])]);

        expect(fn () => Ai::embed('embed-prism'))->toThrow(ProviderNotConfigured::class);
    });
});

describe('a call through the chain', function () {
    it('writes the same ledger row whichever driver answers', function (string $role) {
        FakeHttp::replay('ollama', 'short-answer', '*/api/chat');

        $reply = Ai::chat($role)->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'What is a late fee?']], feature: 'support.reply'));

        $call = ModelCall::sole();

        expect($reply->content)->toContain('late fee')
            ->and($call->provider)->toBe($role)
            ->and($call->feature)->toBe('support.reply')
            ->and([$call->prompt_tokens, $call->completion_tokens])->toBe([33, 20])
            ->and($call->finish_reason)->toBe('stop')
            ->and($call->cost_micros)->toBe(0);
    })->with(['local-prism', 'local-ai-sdk']);

    it('never sends a customer address when redaction is on for the role', function (string $role) {
        config(["ai.providers.{$role}.privacy.redact" => true]);

        app(AiManager::class)->forget();

        FakeHttp::replay('ollama', 'short-answer', '*/api/chat');

        Ai::chat($role)->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'My email is maya.okafor@acme-studios.example.com']], feature: 'support.reply'));

        Http::assertSent(fn (Request $request) => ! str_contains(json_encode($request->data()), 'maya.okafor@acme-studios.example.com'));
    })->with(['local-prism', 'local-ai-sdk']);
});

describe('the tool loop stays the app\'s', function () {
    it('dispatches a package-returned call through the app\'s ToolRunner and ToolGate, once', function (string $role) {
        [$user] = customerWithInvoice('INV-4471');

        throughRole($role);

        $call = ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'lookup_invoice', 'arguments' => ['invoice_number' => '4471', 'month' => null]]]]]];

        $final = ['message' => ['role' => 'assistant', 'content' => 'Invoice #4471 is 18 days overdue.']];

        Http::fake(['*/api/chat' => Http::sequence()
            ->push(['model' => 'llama3.1:8b', 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 120, 'eval_count' => 12] + $call)
            ->push(['model' => 'llama3.1:8b', 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 160, 'eval_count' => 9] + $final)]);

        $result = app(ToolRunner::class)->run(
            messages: [['role' => 'user', 'content' => 'Why is invoice 4471 late?']],
            ctx: new ToolContext($user, null),
            options: ['profile' => 'assistant', 'prompt_version' => 'test.1'],
            feature: 'support.reply',
        );

        expect($result->response->content)->toBe('Invoice #4471 is 18 days overdue.')
            ->and($result->rounds)->toBe(2)
            ->and(ToolCall::count())->toBe(1)
            ->and(ToolCall::sole()->tool)->toBe('lookup_invoice');

        $second = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data())->last();

        expect(json_encode($second['messages']))->toContain('4471');
    })->with(['local-prism', 'local-ai-sdk']);
});

describe('what the packages store and send', function () {
    it('creates no tables of its own: the SDK migrations are published, never run', function () {
        expect(Schema::hasTable('agent_conversations'))->toBeFalse()
            ->and(Schema::hasTable('agent_conversation_messages'))->toBeFalse();
    });

    it('leaves the app\'s ai.providers alone: the SDK\'s own providers are not merged into it', function () {
        expect(array_keys(config('ai.providers')))->not->toContain('openai', 'anthropic', 'gemini', 'ollama');
    });

    it('does not turn on Prism Server, which would add routes to the app', function () {
        expect(config('prism.prism_server.enabled'))->toBeFalse();
    });

    it('sends only to the role\'s own base URL', function (string $role) {
        FakeHttp::replay('ollama', 'short-answer', '*/api/chat');

        Ai::chat($role)->chat(new ChatRequest(messages: [['role' => 'user', 'content' => 'hi']], feature: 'support.reply'));

        Http::assertSentCount(1);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://localhost:11434/api/chat'));
    })->with(['local-prism', 'local-ai-sdk']);
});
