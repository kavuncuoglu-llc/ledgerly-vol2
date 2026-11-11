<?php

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Facades\Ai;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Http;

// Chapter 25, Topic 8: AI_LOCAL_PROVIDER picks which server the `local` role is. config/ai.php reads it once, so the test evaluates the file with each value instead of faking the result.
function localRoleWith(string $value): array|string
{
    putenv("AI_LOCAL_PROVIDER={$value}");
    $_ENV['AI_LOCAL_PROVIDER'] = $value;
    $_SERVER['AI_LOCAL_PROVIDER'] = $value;

    try {
        return (require base_path('config/ai.php'))['providers']['local'];
    } finally {
        putenv('AI_LOCAL_PROVIDER=ollama');
        $_ENV['AI_LOCAL_PROVIDER'] = 'ollama';
        $_SERVER['AI_LOCAL_PROVIDER'] = 'ollama';
    }
}

it('aliases the local role to the server AI_LOCAL_PROVIDER names, and to Ollama by default', function (string $value, string|array $expected) {
    $role = localRoleWith($value);

    is_array($expected)
        ? expect($role)->toBeArray()->and($role['native'])->toBe('ollama')
        : expect($role)->toBe($expected);
})->with([
    'ollama' => ['ollama', ['ollama']],
    'llama' => ['llama', '@local-llama'],
    'local-llama' => ['local-llama', '@local-llama'],
    'vllm' => ['vllm', '@vllm'],
    'triton' => ['triton', '@triton'],
    'a typo falls back to Ollama, which is safe' => ['vlmm', ['ollama']],
]);

it('sends the same request to a different server and URL, and logs it as the local role, whichever server it is', function (string $alias, string $url, string $key, string $model, string $native) {
    config(['ai.providers.local' => "@{$alias}"]);

    app(AiManager::class)->forget();

    Http::fake(["{$url}/chat/completions" => Http::response(chatBody('The same reply.', model: $model))]);

    $response = Ai::for('support.reply')->chat(ChatRequest::make([['role' => 'user', 'content' => 'late fee?']], [], 'support.reply'));

    expect($response->content)->toBe('The same reply.')
        ->and(Ai::model('local'))->toBe($model)
        ->and(Ai::config('local')['native'])->toBe($native)
        ->and(ModelCall::sole()->provider)->toBe('local');

    Http::assertSent(fn ($request) => $request->url() === "{$url}/chat/completions" && $request->hasHeader('Authorization', "Bearer {$key}"));
})->with([
    'llama-server' => ['local-llama', 'http://llama.test:8080/v1', 'llama-test-key', 'ledgerly-support', 'llama-server'],
    'vllm' => ['vllm', 'http://vllm.test:8000/v1', 'vllm-test-key', 'ledgerly-support', 'vllm'],
    'triton' => ['triton', 'http://triton.test:9000/v1', 'triton-test-key', 'chat', 'triton'],
]);

it('declares Triton as a provider on the compatible driver with the key from the environment and the capabilities of Figure 25-8', function () {
    $provider = config('ai.providers.triton');

    expect($provider['driver'])->toBe('openai-compatible')
        ->and($provider['base_url'])->toBe('http://triton.test:9000/v1')
        ->and($provider['api_key'])->toBe('triton-test-key')
        ->and($provider['model'])->toBe('chat')
        ->and($provider['capabilities'])->toBe(['structured', 'tools'])
        ->and(file_get_contents(base_path('config/ai.php')))->not->toContain('triton-test-key');
});

it('lists every server behind the role in ai:providers', function () {
    $this->artisan('ai:providers')
        ->expectsOutputToContain('triton')
        ->assertSuccessful();
});

it('has the conformance suite for every provider name the switch can pick', function () {
    $suite = file_get_contents(base_path('tests/Feature/Ai/DriverConformanceTest.php'));

    expect($suite)->toContain("'local', 'frontier', 'vllm'");        // the gate for switching (Topic 8); Triton's OpenAI frontend is the same shape as vLLM's
});
