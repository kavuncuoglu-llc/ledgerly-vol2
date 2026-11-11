<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function llamaDoctor(array $options = []): array
{
    $status = Artisan::call('ai:doctor', ['--provider' => 'local-llama'] + $options);

    return [$status, Artisan::output()];
}

beforeEach(function () {
    config([
        'ai.providers.local-llama.base_url' => 'http://llama.test:8080/v1',
        'ai.providers.local-llama.api_key' => 'llama-test-key',
        'ai.budget.window' => 8192,
        'ai.concurrency' => 4,
        'ai.token_counter' => 'server',
        'ai.llama.ngl' => '99',
        'ai.llama.cache_type' => 'q8_0',
    ]);
});

it('passes every llama-server check, in the order of Figure 23-9', function () {
    fakeLlamaServer();

    [$status, $output] = llamaDoctor();

    expect($status)->toBe(0)
        ->and($output)->toContain('llama-server at http://llama.test:8080')
        ->and($output)->toContain('✓ /health ok · Build b11829-184f653')
        ->and($output)->toContain('✓ requests without the API key are refused')
        ->and($output)->toContain('✓ slots 4 = ai queue processes 4')
        ->and($output)->toContain('✓ context per slot 8192 ≥ budget window 8192')
        ->and($output)->toContain('✓ -ngl 99: all layers requested on the GPU')
        ->and($output)->toContain('✓ /metrics reachable · kv cache 25% used · kv cache type q8_0 (from .env)')
        ->and($output)->toContain('✓ /tokenize exact counter active (10 tokens for the test sentence)')
        ->and($output)->toContain('✓ /v1/chat/completions round-trip')
        ->and($output)->toContain('✓ license recorded for all providers')
        ->and($output)->toContain('0 warnings, 0 failures');
});

it('fails when -c divided by -np is smaller than the budget window, and says what to set', function () {
    fakeLlamaServer(['slot_ctx' => 4096]);

    [$status, $output] = llamaDoctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ context per slot 4096 < budget window 8192')
        ->and($output)->toContain('-c 32768 -np 4');
});

it('warns when the slots and the queue workers differ', function () {
    fakeLlamaServer(['slots' => 2]);

    expect(llamaDoctor()[1])->toContain('⚠ slots 2 ≠ ai queue processes 4');
});

it('warns about a server with no key, and about an app with none', function () {
    fakeLlamaServer(['unauthenticated' => true]);

    expect(llamaDoctor()[1])->toContain('⚠ a request with no API key got 200');

    config(['ai.providers.local-llama.api_key' => '']);

    expect(llamaDoctor()[1])->toContain('⚠ LLAMA_API_KEY is empty in .env');
});

it('warns about partial offload and about an unset -ngl, and accepts the CPU profile', function () {
    fakeLlamaServer();

    config(['ai.llama.ngl' => '20']);
    expect(llamaDoctor()[1])->toContain('⚠ -ngl 20: only some layers are on the GPU');

    config(['ai.llama.ngl' => null]);
    expect(llamaDoctor()[1])->toContain('⚠ LLAMA_NGL is not set in .env');

    config(['ai.llama.ngl' => '0']);
    expect(llamaDoctor()[1])->toContain('✓ -ngl 0: CPU profile');
});

it('warns when metrics or slots are switched off, and when the server could count exactly but the app estimates', function () {
    fakeLlamaServer(['metrics' => false]);
    config(['ai.token_counter' => 'ratio']);

    $output = llamaDoctor()[1];

    expect($output)->toContain('⚠ /metrics is not available: start llama-server with --metrics')
        ->and($output)->toContain('⚠ /tokenize works, but AI_TOKEN_COUNTER=ratio');

    fakeLlamaServer(['slots' => 0]);

    expect(llamaDoctor()[1])->toContain('⚠ /slots is not available');
});

it('stops at one failure when nothing answers, or the model is still loading', function () {
    Http::fake(['*' => Http::failedConnection()]);

    [$status, $output] = llamaDoctor();

    expect($status)->toBe(1)->and($output)->toContain('✗ not reachable: nothing answered at http://llama.test:8080')->and($output)->toContain('0 warnings, 1 failure');

    fakeLlamaServer(['health' => 503]);

    expect(llamaDoctor()[1])->toContain('✗ /health 503: the server is up but still loading the model');
});

it('fails when the one-token completion errors', function () {
    fakeLlamaServer(['chat_ok' => false]);

    [$status, $output] = llamaDoctor();

    expect($status)->toBe(1)->and($output)->toContain('✗ /v1/chat/completions round trip failed');
});

it('has the three-state health line, and the doctor follows the local role when it is an alias', function () {
    fakeLlamaServer();

    [$status, $output] = llamaDoctor(['--health' => true]);

    expect($status)->toBe(0)->and($output)->toContain('reachable: yes · loaded: yes · warm: yes (completion');

    config(['ai.providers.local' => '@local-llama']);

    $status = Artisan::call('ai:doctor');

    expect($status)->toBe(0)->and(Artisan::output())->toContain('llama-server at http://llama.test:8080');

    fakeLlamaServer(['health' => 503]);

    [$status, $output] = llamaDoctor(['--health' => true]);

    expect($status)->toBe(1);
});
