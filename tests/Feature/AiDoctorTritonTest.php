<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function tritonDoctor(array $options = []): array
{
    $status = Artisan::call('ai:doctor', ['--provider' => 'triton'] + $options);

    return [$status, Artisan::output()];
}

beforeEach(function () {
    config(['ai.providers.triton.base_url' => 'http://triton.test:9000/v1', 'ai.providers.triton.api_key' => 'triton-test-key']);
});

it('passes every Triton check: readiness, the four models, the proxy, model control, the batching numbers and a round trip', function () {
    fakeTritonServer();

    [$status, $output] = tritonDoctor();

    expect($status)->toBe(0)
        ->and($output)->toContain('Triton at http://triton.test:9000')
        ->and($output)->toContain('✓ /v2/health/ready ok')
        ->and($output)->toContain('✓ model chat ready')
        ->and($output)->toContain('✓ model embedder ready')
        ->and($output)->toContain('✓ model reranker ready')
        ->and($output)->toContain('✓ model support_pipeline ready')
        ->and($output)->toContain('✓ auth required: a request without the API key is refused')
        ->and($output)->toContain('✓ model control (load and unload) is not reachable through this address')
        ->and($output)->toContain('✓ /metrics reachable · embedder 1.0 requests per execution · reranker 15.5 requests per execution')
        ->and($output)->toContain('✓ /v1/chat/completions round-trip')
        ->and($output)->toContain('0 warnings, 0 failures');
});

it('fails when the chat model did not load and only warns about the optional ones', function () {
    fakeTritonServer(['models' => ['embedder']]);

    [$status, $output] = tritonDoctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ model chat is not ready')
        ->and($output)->toContain('⚠ model reranker is not ready (not needed for chat)')
        ->and($output)->toContain('export_onnx.py');

    fakeTritonServer(['models' => ['chat']]);

    expect(tritonDoctor()[0])->toBe(0);
});

it('fails with the start-up advice when nothing answers, and explains a 401 from the proxy', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

    [$status, $output] = tritonDoctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ not reachable')
        ->and($output)->toContain('--profile triton');

    fakeTritonServer(['key' => 'a-different-key']);

    expect(tritonDoctor()[1])->toContain('✗ 401 from the proxy: TRITON_API_KEY in .env is not the key');
});

it('fails when Triton is up but not ready', function () {
    fakeTritonServer(['ready' => 503]);

    [$status, $output] = tritonDoctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('/v2/health/ready answered 503');
});

it('warns when there is no proxy: no auth, and the model-control API open', function () {
    fakeTritonServer(['unauthenticated' => true, 'model_control' => 200]);

    [, $output] = tritonDoctor();

    expect($output)->toContain('⚠ a request with no API key got 200, not 401: there is no authenticating proxy')
        ->and($output)->toContain('⚠ POST /v2/repository/index answered 200');

    config(['ai.providers.triton.api_key' => '']);

    expect(tritonDoctor()[1])->toContain('⚠ TRITON_API_KEY is empty in .env');
});

it('warns when /metrics is not there', function () {
    fakeTritonServer(['metrics' => false]);

    expect(tritonDoctor()[1])->toContain('⚠ /metrics is not available');
});

it('prints the three-state health line with the breaker', function () {
    fakeTritonServer();

    [$status, $output] = tritonDoctor(['--health' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('reachable: yes · loaded: yes (chat model ready) · warm: yes (completion')
        ->and($output)->toContain('· breaker closed');

    fakeTritonServer(['models' => []]);

    [$status, $output] = tritonDoctor(['--health' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('loaded: no (the chat model is not ready)');

    fakeTritonServer(['chat_ok' => false]);

    expect(tritonDoctor(['--health' => true])[1])->toContain('a one-token completion failed');
});
