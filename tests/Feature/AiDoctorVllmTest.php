<?php

use App\Ai\AiManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function vllmDoctor(array $options = []): array
{
    $status = Artisan::call('ai:doctor', ['--provider' => 'vllm'] + $options);

    return [$status, Artisan::output()];
}

beforeEach(function () {
    config([
        'ai.providers.vllm.base_url' => 'http://vllm.test:8000/v1',
        'ai.providers.vllm.api_key' => 'vllm-test-key',
        'ai.budget.window' => 8192,
        'ai.concurrency' => 16,
        'ai.vllm.revision' => '870a1177ed082c0ba5dbefd62573ab8f91803f6a',
        'ai.vllm.model_repo' => 'RedHatAI/Meta-Llama-3.1-8B-Instruct-FP8',
        'ai.vllm.max_num_seqs' => 32,
        'ai.vllm.tool_parser' => 'llama3_json',
    ]);
});

it('passes every vLLM check, in the order of Figure 24-8', function () {
    fakeVllmServer();

    [$status, $output] = vllmDoctor();

    expect($status)->toBe(0)
        ->and($output)->toContain('vLLM at http://vllm.test:8000')
        ->and($output)->toContain('✓ /health ok · model ledgerly-support (FP8) · revision pinned')
        ->and($output)->toContain('✓ max-model-len 8192 ≥ budget window 8192 (rejects overflow: ContextTooLong mapped)')
        ->and($output)->toContain('✓ KV cache 212,480 tokens · max concurrency ~26 at full window')
        ->and($output)->toContain('✓ prefix caching on')
        ->and($output)->toContain('✓ tool parser llama3_json')
        ->and($output)->toContain('✓ auth required: a request without the API key is refused')
        ->and($output)->toContain('✓ /metrics reachable · 3 running, 0 waiting')
        ->and($output)->toContain('✓ /v1/chat/completions round-trip')
        ->and($output)->toContain('0 warnings, 0 failures');
});

it('fails when the server is not there, and says how to start it', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    [$status, $output] = vllmDoctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ not reachable')
        ->and($output)->toContain('--profile vllm');
});

it('fails on a max-model-len below the budget window and warns on one far above it', function () {
    fakeVllmServer(['max_model_len' => 4096]);

    [$status, $output] = vllmDoctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ max-model-len 4096 < budget window 8192');

    fakeVllmServer(['max_model_len' => 131072]);

    [$status, $output] = vllmDoctor();

    expect($status)->toBe(0)
        ->and($output)->toContain('⚠ max-model-len 131072');
});

it('warns when the queue workers outnumber the sequences the cache holds', function () {
    fakeVllmServer(['blocks' => 1000]);

    [, $output] = vllmDoctor();

    expect($output)->toContain('KV cache 16,000 tokens · max concurrency ~2 at full window')
        ->and($output)->toContain('⚠ ai queue processes 16 (AI_CONCURRENCY) > 1 full-window sequences');
});

it('warns about a queue wider than --max-num-seqs', function () {
    fakeVllmServer();

    config(['ai.vllm.max_num_seqs' => 8]);

    expect(vllmDoctor()[1])->toContain('⚠ ai queue processes 16 > --max-num-seqs 8');
});

it('warns about prefix caching off, about a server with no key, and about an app with none', function () {
    fakeVllmServer(['prefix_caching' => 'False']);

    expect(vllmDoctor()[1])->toContain('⚠ prefix caching is OFF');

    fakeVllmServer(['unauthenticated' => true]);

    expect(vllmDoctor()[1])->toContain('⚠ a request with no API key got 200');

    config(['ai.providers.vllm.api_key' => '']);

    expect(vllmDoctor()[1])->toContain('⚠ VLLM_API_KEY is empty in .env');
});

it('warns when the revision is not pinned, because an unpinned model can change under you', function () {
    fakeVllmServer();

    config(['ai.vllm.revision' => '']);

    expect(vllmDoctor()[1])->toContain('revision NOT pinned');
});

it('warns when /metrics is off and says where the capacity line is instead', function () {
    fakeVllmServer(['metrics' => false]);

    [, $output] = vllmDoctor();

    expect($output)->toContain('GPU KV cache size')
        ->and($output)->toContain('⚠ /metrics is not available');
});

it('prints the three-state health line, and tells you to run ai:warm when it is cold', function () {
    fakeVllmServer();

    [$status, $output] = vllmDoctor(['--health' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('reachable: yes · loaded: yes (KV cache reported) · warm: yes (completion');

    config(['ai.health.warm_seconds' => -1]);

    [, $cold] = vllmDoctor(['--health' => true]);

    expect($cold)->toContain('warm: no (first completion')
        ->and($cold)->toContain('run ai:warm');
});

it('fails the health probe when the server is down or the completion errors', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    [$status, $output] = vllmDoctor(['--health' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('reachable: no');

    fakeVllmServer(['chat_ok' => false]);

    [$status, $output] = vllmDoctor(['--health' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('a one-token completion failed');
});

it('is the server the local role reaches when AI_LOCAL_PROVIDER is vllm', function () {
    config(['ai.providers.local' => '@vllm']);

    app(AiManager::class)->forget();

    fakeVllmServer();

    $status = Artisan::call('ai:doctor');

    expect($status)->toBe(0)
        ->and(Artisan::output())->toContain('vLLM at http://vllm.test:8000');
});
