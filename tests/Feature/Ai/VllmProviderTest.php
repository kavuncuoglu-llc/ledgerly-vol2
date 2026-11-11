<?php

use App\Ai\AiManager;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Exceptions\ContextTooLong;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function localIsVllm(): void
{
    config(['ai.providers.local' => '@vllm']);

    app(AiManager::class)->forget();
}

it('declares vLLM as a provider on the compatible driver, with its key and URL from the environment', function () {
    $provider = config('ai.providers.vllm');

    expect($provider['driver'])->toBe('openai-compatible')
        ->and($provider['native'])->toBe('vllm')
        ->and($provider['base_url'])->toBe('http://vllm.test:8000/v1')                    // VLLM_URL, forced by phpunit.xml
        ->and($provider['api_key'])->toBe('vllm-test-key')                                 // VLLM_API_KEY: nothing in config/ai.php is a key
        ->and($provider['model'])->toBe('ledgerly-support')                                // the --served-model-name alias, not a repo path
        ->and($provider['capabilities'])->toContain('tools', 'structured', 'prefix_cache', 'lora')
        ->and(file_get_contents(base_path('config/ai.php')))->not->toContain('vllm-test-key');
});

it('declares the capabilities of Figure 24-8 and keeps tools and structured output', function () {
    expect(Ai::chat('vllm')->supports(Capability::Tools))->toBeTrue()
        ->and(Ai::chat('vllm')->supports(Capability::StructuredOutput))->toBeTrue()
        ->and(Ai::chat('vllm')->supports(Capability::PrefixCache))->toBeTrue()
        ->and(Ai::chat('vllm')->supports(Capability::Lora))->toBeTrue()
        ->and(Ai::chat('vllm')->supports(Capability::Grammar))->toBeFalse()               // that is llama-server's
        ->and(Ai::chat('vllm')->supports(Capability::ContextWindowOverride))->toBeFalse();   // vLLM rejects an over-long request; it does not take a per-request window
});

it('sends the bearer key and the alias to the vLLM URL, and nothing of it anywhere else', function () {
    Http::fake(['vllm.test:8000/v1/chat/completions' => Http::response(chatBody('Fees start after a grace period.', model: 'ledgerly-support'))]);

    $response = Ai::chat('vllm')->chat(ChatRequest::make([['role' => 'user', 'content' => 'late fee?']], [], 'support.reply'));

    expect($response->content)->toBe('Fees start after a grace period.');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://vllm.test:8000/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer vllm-test-key')
        && $request['model'] === 'ledgerly-support');
});

it('maps vLLM\'s 400 for an over-length request to ContextTooLong and does not retry it', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'object' => 'error',
        'message' => "This model's maximum context length is 8192 tokens. However, you requested 9120 tokens (8720 in the messages, 400 in the completion). Please reduce the length of the messages or completion.",
        'type' => 'BadRequestError',
        'param' => null,
        'code' => 400,
    ], 400)]);

    $error = null;

    try {
        Ai::chat('vllm')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'support.reply'));
    } catch (Throwable $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(ContextTooLong::class)
        ->and($error->retryable())->toBeFalse();

    Http::assertSentCount(1);
});

it('makes the local role an alias of vLLM that nothing above the manager notices', function () {
    localIsVllm();

    Http::fake(['vllm.test:8000/v1/chat/completions' => Http::response(chatBody('A reply.', model: 'ledgerly-support'))]);

    $response = Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hello']], [], 'support.reply'));

    expect($response->content)->toBe('A reply.')
        ->and(Ai::model('local'))->toBe('ledgerly-support')
        ->and(Ai::configured('local'))->toBeTrue()
        ->and(ModelCall::sole()->provider)->toBe('local');                              // the role's name, so ai:stats reads the same on any server
});

it('streams through the same driver, with the usage chunk vLLM sends last', function () {
    localIsVllm();

    $chunks = [
        ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
        ['choices' => [['index' => 0, 'delta' => ['content' => ' there'], 'finish_reason' => null]]],
        ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ['choices' => [], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 2]],
    ];

    Http::fake(['vllm.test:8000/v1/chat/completions' => fn () => Http::response(implode('', array_map(fn (array $chunk) => 'data: '.json_encode($chunk)."\n\n", $chunks))."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

    $text = '';

    foreach (Ai::chat('local')->stream(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'support.reply')) as $event) {
        $text .= $event instanceof Delta
            ? $event->text
            : '';
    }

    expect($text)->toBe('Hello there');
});

it('can be compared by name: ai:providers lists it with its license and says when it is the local role', function () {
    $this->artisan('ai:providers')
        ->expectsOutputToContain('vllm')
        ->expectsOutputToContain('Llama 3.1 Community License')
        ->assertSuccessful();
});
