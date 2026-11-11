<?php

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Contracts\FinishReason;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Exceptions\ProviderNotConfigured;
use App\Ai\Testing\ScriptedJsonInvalid;
use App\Ai\Testing\UnscriptedCall;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\AssertionFailedError;

function haveFrontier(): void
{
    config([
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'frontier-example',
    ]);
}

describe('the manager', function () {
    it('resolves role names to decorated drivers and caches them', function () {
        $local = Ai::chat('local');

        expect($local)->toBeInstanceOf(UsageLoggingModel::class)
            ->and($local->name())->toBe('local')
            ->and($local->unwrap())->toBeInstanceOf(OpenAiCompatibleDriver::class)
            ->and(Ai::chat('local'))->toBe($local)
            ->and(Ai::chat()->name())->toBe(config('ai.default'));
    });

    it('routes a feature to its provider through the features map, and an unlisted feature to the default', function () {
        config(['ai.features' => ['ticket.classify' => ['provider' => 'vision'], 'support.reply' => ['provider' => 'local']]]);

        expect(Ai::for('ticket.classify')->name())->toBe('vision')
            ->and(Ai::for('support.reply')->name())->toBe('local')
            ->and(Ai::for('something.new')->name())->toBe('local');
    });

    it('moving a feature to another provider is a config line, and the feature code never changes', function () {
        haveFrontier();

        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        config(['ai.features.ticket.classify' => null]);
        config(['ai.features' => ['ticket.classify' => ['provider' => 'frontier']] + config('ai.features')]);

        Ai::for('ticket.classify')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'ticket.classify'));

        expect(ModelCall::sole()->provider)->toBe('frontier');
    });

    it('refuses a name it does not know, with the name in the message', function () {
        expect(fn () => Ai::chat('nonsense'))->toThrow(ProviderNotConfigured::class, "no provider named 'nonsense'");
    });

    it('treats frontier as unset by default: it is not configured and cannot be resolved', function () {
        config(['ai.providers.frontier.base_url' => null, 'ai.providers.frontier.api_key' => null, 'ai.providers.frontier.model' => null]);

        expect(Ai::configured('frontier'))->toBeFalse()
            ->and(Ai::escalationFor('support.reply'))->toBeNull()
            ->and(fn () => Ai::chat('frontier'))->toThrow(ProviderNotConfigured::class, 'FRONTIER_URL');
    });

    it('does not count a hosted provider with a URL but no key as set up', function () {
        config(['ai.providers.frontier.base_url' => 'https://frontier.example.com/v1', 'ai.providers.frontier.api_key' => '', 'ai.providers.frontier.model' => 'm']);

        expect(Ai::configured('frontier'))->toBeFalse();
    });

    it('offers an escalation only once the target is set up', function () {
        haveFrontier();

        expect(Ai::escalationFor('support.reply'))->toBe('frontier')
            ->and(Ai::escalationFor('ticket.classify'))->toBeNull();
    });

    it('says plainly when a driver is configured that this repository does not ship', function () {
        haveFrontier();

        config(['ai.providers.frontier.driver' => 'anthropic']);

        expect(fn () => Ai::chat('frontier'))->toThrow(ProviderNotConfigured::class, "'anthropic' driver");
    });

    it('lets the frontier driver be swapped by environment without touching a feature', function () {
        haveFrontier();

        expect(config('ai.providers.frontier.driver'))->toBe('openai-compatible');
    });

    it('reports capabilities from the driver underneath, through every decorator', function () {
        haveFrontier();

        expect(Ai::chat('local')->supports(Capability::Tools))->toBeTrue()
            ->and(Ai::chat('local')->supports(Capability::Vision))->toBeFalse()
            ->and(Ai::chat('vision')->supports(Capability::Vision))->toBeTrue()
            ->and(Ai::chat('frontier')->supports(Capability::Vision))->toBeTrue()
            ->and(Ai::chat('local')->supports(Capability::CacheBreakpoints))->toBeFalse();
    });
});

describe('capability interfaces and raw()', function () {
    it('asks for a bigger context window without the feature knowing which server it is', function () {
        config(['ai.providers.local.native' => null]);                                 // an Ollama provider drops to /api/chat instead (OllamaNativeDriverTest)

        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        $model = Ai::for('conversation.summarize');

        expect($model->supports(Capability::ContextWindowOverride))->toBeTrue()
            ->and($model)->toBeInstanceOf(SupportsContextWindowOverride::class);

        $model->withContextWindow(16_384)->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'conversation.summarize'));

        Http::assertSent(fn (Request $request) => $request['num_ctx'] === 16_384);

        Ai::for('conversation.summarize')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'conversation.summarize'));

        Http::assertSent(fn (Request $request) => ! isset($request['num_ctx']));          // the override was on a copy
    });

    it('is a safe no-op on a provider that cannot', function () {
        config(['ai.providers.local.capabilities' => ['tools']]);

        $model = Ai::chat('local');

        expect($model->supports(Capability::ContextWindowOverride))->toBeFalse();
    });

    it('sends a provider-shaped request through raw() with the decorators still applied', function () {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'llama3.1:8b']]])]);

        $response = Ai::chat('local')->raw('GET', '/models');

        expect($response['data'][0]['id'])->toBe('llama3.1:8b');
    });
});

describe('Ai::fake()', function () {
    it('returns scripted replies and records what it was sent, with the sampling profile already merged', function () {
        Ai::fake()
            ->for('ticket.classify')->respondWith('billing', times: 3);

        for ($i = 0; $i < 3; $i++) {
            expect(Ai::for('ticket.classify')->chat(ChatRequest::make([['role' => 'user', 'content' => 'x']], [], 'ticket.classify'))->content)->toBe('billing');
        }

        Ai::assertSent(fn (ChatRequest $request) => $request->feature === 'ticket.classify' && $request->options['temperature'] === 0);

        Ai::assertSentCount('ticket.classify', 3);

        Ai::assertNothingSentTo('frontier');

        Http::assertNothingSent();
    });

    it('fails an assertion with what was actually sent', function () {
        Ai::fake()->for('support.reply')->respondWith('hi');

        Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'x']], [], 'support.reply'));

        expect(fn () => Ai::assertNothingSentTo('local'))->toThrow(AssertionFailedError::class, 'Expected nothing to be sent to');
    });

    it('breaks loudly when a feature was not scripted, so a forgotten script cannot pass', function () {
        Ai::fake();

        Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'x']], [], 'support.reply'));
    })->throws(UnscriptedCall::class, "'support.reply'");

    it('scripts a tool call, a stream and an error', function () {
        Ai::fake()
            ->for('support.reply')
            ->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471'], id: 'call_1')
            ->respondWithStream(['The ', 'fee ', 'is 1.5%.'])
            ->respondWithError(new RuntimeException('down'));

        $request = ChatRequest::make([['role' => 'user', 'content' => 'x']], [], 'support.reply');

        $first = Ai::for('support.reply')->chat($request);

        expect($first->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($first->toolCalls[0]['id'])->toBe('call_1')
            ->and($first->content)->toBeNull();

        expect(Ai::for('support.reply')->chat($request)->content)->toBe('The fee is 1.5%.');

        expect(fn () => Ai::for('support.reply')->chat($request))->toThrow(RuntimeException::class, 'down');
    });

    it('validates scripted JSON against the response format, so bad scripts fail the way production would', function () {
        $format = ['type' => 'json_schema', 'json_schema' => ['name' => 'ticket', 'schema' => [
            'type' => 'object',
            'required' => ['category', 'invoice_number'],
            'properties' => ['category' => ['type' => 'string'], 'invoice_number' => ['type' => ['string', 'null']]],
        ]]];

        $request = new ChatRequest(messages: [['role' => 'user', 'content' => 'x']], feature: 'ticket.extract', responseFormat: $format);

        Ai::fake()
            ->for('ticket.extract')
            ->respondWithJson(['category' => 'billing', 'invoice_number' => null])
            ->respondWith('not json at all')
            ->respondWithJson(['category' => 'billing'])
            ->respondWithJson(['category' => 7, 'invoice_number' => null]);

        expect(Ai::for('ticket.extract')->chat($request)->content)->toBe('{"category":"billing","invoice_number":null}');

        expect(fn () => Ai::for('ticket.extract')->chat($request))->toThrow(ScriptedJsonInvalid::class, 'not a JSON object');
        expect(fn () => Ai::for('ticket.extract')->chat($request))->toThrow(ScriptedJsonInvalid::class, "'invoice_number'");
        expect(fn () => Ai::for('ticket.extract')->chat($request))->toThrow(ScriptedJsonInvalid::class, "'category'");
    });

    it('embeds deterministically, so retrieval code can be tested without a server', function () {
        Ai::fake();

        $model = Ai::embed();

        $first = $model->embed(['late fee'], EmbedKind::Query);

        expect($first)->toHaveCount(1)
            ->and($first[0])->toHaveCount(768)
            ->and($model->embed(['late fee'], EmbedKind::Query))->toBe($first)
            ->and($model->embed(['refund'], EmbedKind::Query))->not->toBe($first);
    });
});

describe('the debug trapdoor', function () {
    afterEach(function () {
        foreach (glob(storage_path('logs/ai-debug-*.log')) as $file) {
            unlink($file);
        }
    });

    it('writes the exact request and response for the listed conversation, and nothing for others', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('Debug me.'))]);

        config(['ai.debug.conversations' => [77]]);

        Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'one']], ['conversation_id' => 77], 'support.reply'));
        Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'two']], ['conversation_id' => 78], 'support.reply'));

        $lines = file(storage_path('logs/ai-debug-77.log'), FILE_IGNORE_NEW_LINES);

        $entry = json_decode($lines[0], true);

        expect($lines)->toHaveCount(1)
            ->and($entry['request']['messages'][0]['content'])->toBe('one')
            ->and($entry['response']['choices'][0]['message']['content'])->toBe('Debug me.')
            ->and($entry['provider'])->toBe('local')
            ->and(file_exists(storage_path('logs/ai-debug-78.log')))->toBeFalse()
            ->and(json_encode($entry))->not->toContain('Bearer');
    });

    it('can watch a whole feature', function () {
        Http::fake(['*/chat/completions' => Http::response(chatBody('Feature.'))]);

        config(['ai.debug.features' => ['ticket.classify']]);

        Ai::for('ticket.classify')->chat(ChatRequest::make([['role' => 'user', 'content' => 'x']], [], 'ticket.classify'));

        expect(file_exists(storage_path('logs/ai-debug-ticket.classify.log')))->toBeTrue();
    });

    it('warns once when it has to drop an option for a provider', function () {
        Log::spy();

        haveFrontier();

        Http::fake(['*/chat/completions' => Http::response(chatBody('ok'))]);

        $request = ChatRequest::make([['role' => 'user', 'content' => 'x']], ['min_p' => 0.1], 'x.y');

        Ai::chat('frontier')->chat($request);
        Ai::chat('frontier')->chat($request);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, "does not accept 'min_p'"))->once();
    });
});

describe('the boundary', function () {
    it('keeps raw() and the driver classes inside app/Ai/Drivers, app/Ai/Decorators, app/Ai/Native and the manager', function () {
        $allowed = ['app/Ai/Drivers/', 'app/Ai/Decorators/', 'app/Ai/Native/', 'app/Ai/AiManager.php', 'app/Ai/Contracts/'];

        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if (collect($allowed)->contains(fn (string $prefix) => str_starts_with($relative, $prefix))) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match('/->raw\(|App\\\\Ai\\\\Drivers\\\\(OpenAiCompatibleDriver|AnthropicDriver|GeminiDriver)/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        expect($offenders)->toBe([]);                                              // Chapter 19, Topic 5: the layer stays a layer because something fails when it is bypassed
    });

    it('lets no feature build its own HTTP request to a model endpoint', function () {
        $offenders = [];

        foreach (['Retrieval', 'Support', 'Triage', 'Agents', 'Structured', 'Conversations', 'Memory', 'Tools', 'Ingestion', 'Embeddings'] as $directory) {
            foreach (glob(base_path("app/Ai/{$directory}/*.php")) as $file) {
                if (preg_match('#/chat/completions|/embeddings#', file_get_contents($file)) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file);
                }
            }
        }

        expect($offenders)->toBe([]);
    });

    it('has retired AiHttpClient', function () {
        expect(file_exists(app_path('Ai/Http/AiHttpClient.php')))->toBeFalse()
            ->and(app()->bound(AiManager::class))->toBeTrue();
    });
});
