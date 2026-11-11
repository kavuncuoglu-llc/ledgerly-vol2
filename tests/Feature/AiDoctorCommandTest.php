<?php

use App\Ai\Doctor\PortProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function doctor(array $options = []): array
{
    $status = Artisan::call('ai:doctor', $options);

    return [$status, Artisan::output()];
}

function fakePorts(array $exposedOn = []): void
{
    app()->instance(PortProbe::class, new class($exposedOn) extends PortProbe
    {
        public function __construct(private array $exposed) {}

        public function exposedOn(int $port): array
        {
            return $this->exposed;
        }
    });
}

beforeEach(function () {
    config([
        'ai.providers.local.model' => 'ledgerly-support',
        'ai.providers.local.base_url' => 'http://localhost:11434/v1',
        'ai.embed_model' => 'nomic-embed-text',
        'ai.vision_model' => 'llama3.2-vision:11b',
        'ai.budget.window' => 8192,
        'ai.concurrency' => 2,
        'ai.ollama.server.num_parallel' => '2',
        'ai.ollama.server.keep_alive' => '30m',
    ]);

    fakePorts();
});

it('passes every check on a well-set-up server, with one warning for a vision model that spilled to the CPU', function () {
    fakeOllamaServer(['loaded' => [
        ['name' => 'ledgerly-support:latest', 'size' => 6_657_199_309, 'size_vram' => 6_657_199_309],
        ['name' => 'llama3.2-vision:11b', 'size' => 10_000_000_000, 'size_vram' => 7_100_000_000],
    ]]);

    [$status, $output] = doctor();

    expect($status)->toBe(0)
        ->and($output)->toContain('Ollama at http://localhost:11434')
        ->and($output)->toContain('✓ reachable; version 0.40.14')
        ->and($output)->toContain('✓ models present: ledgerly-support (llama3.1:8b-instruct-q4_K_M + params), nomic-embed-text (nomic-bert 137M F16), llama3.2-vision:11b')
        ->and($output)->toContain('✓ ledgerly-support num_ctx 8192 ≥ budget window 8192')
        ->and($output)->toContain('✓ ledgerly-support:latest loaded: 100% GPU (6.2 GB)')
        ->and($output)->toContain('⚠ llama3.2-vision:11b loaded: 71%/29% GPU/CPU — will be slow; consider OLLAMA_MAX_LOADED_MODELS=2 or a smaller model')
        ->and($output)->toContain('✓ OLLAMA_NUM_PARALLEL=2 matches ai queue processes (2)')
        ->and($output)->toContain('✓ OLLAMA_KEEP_ALIVE=30m (not the 5m default)')
        ->and($output)->toContain('✓ port 11434 not bound on a public interface')
        ->and($output)->toContain('with exact counts')
        ->and($output)->toContain('✓ license recorded for all providers')
        ->and($output)->toContain('1 warning, 0 failures');
});

it('fails the build when num_ctx is below the budget window', function () {
    fakeOllamaServer(['num_ctx' => 4096]);

    [$status, $output] = doctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ ledgerly-support num_ctx 4096 < budget window 8192: prompts will be silently truncated. Set num_ctx in the Modelfile.')
        ->and($output)->toContain('1 failure');
});

it('treats a Modelfile with no num_ctx as the 4096 default, and says so', function () {
    fakeOllamaServer(['num_ctx' => null]);

    [$status, $output] = doctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('num_ctx 4096 < budget window 8192 (the Modelfile sets none, so the server default applies)');
});

it('follows the budget window: a smaller window passes the same Modelfile', function () {
    fakeOllamaServer(['num_ctx' => 4096]);
    config(['ai.budget.window' => 4096]);

    expect(doctor()[1])->toContain('✓ ledgerly-support num_ctx 4096 ≥ budget window 4096');
});

it('names the missing models and how to get each', function () {
    fakeOllamaServer(['models' => ['nomic-embed-text']]);

    [$status, $output] = doctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ models missing: ledgerly-support (ollama create ledgerly-support -f ops/ollama/Modelfile.ledgerly-support), llama3.2-vision:11b (ollama pull llama3.2-vision:11b)')
        ->and($output)->not->toContain('num_ctx');
});

it('stops at one failure when nothing answers', function () {
    Http::fake(['*' => Http::failedConnection()]);

    [$status, $output] = doctor();

    expect($status)->toBe(1)
        ->and($output)->toContain('✗ not reachable: nothing answered at http://localhost:11434')
        ->and($output)->toContain('0 warnings, 1 failure');
});

it('warns about the 5 minute keep-alive default, set or unset', function () {
    fakeOllamaServer();

    config(['ai.ollama.server.keep_alive' => '5m']);
    expect(doctor()[1])->toContain('⚠ OLLAMA_KEEP_ALIVE=5m is the 5m default');

    config(['ai.ollama.server.keep_alive' => '']);
    expect(doctor()[1])->toContain('⚠ OLLAMA_KEEP_ALIVE is not set in .env');

    config(['ai.ollama.server.keep_alive' => '-1']);
    expect(doctor()[1])->toContain('✓ OLLAMA_KEEP_ALIVE=-1 (not the 5m default)');
});

it('warns when the server slots and the queue processes differ, or when it cannot tell', function () {
    fakeOllamaServer();

    config(['ai.ollama.server.num_parallel' => '1']);
    expect(doctor()[1])->toContain('⚠ OLLAMA_NUM_PARALLEL=1 does not match the 2 ai queue processes');

    config(['ai.ollama.server.num_parallel' => '']);
    expect(doctor()[1])->toContain('⚠ OLLAMA_NUM_PARALLEL is not set in .env');
});

it('warns when the port answers on a non-loopback address, and leaves a remote server to its own host', function () {
    fakeOllamaServer();
    fakePorts(['192.168.1.20']);

    expect(doctor()[1])->toContain('⚠ port 11434 answers on 192.168.1.20: Ollama has no authentication');

    config(['ai.providers.local.base_url' => 'http://ollama:11434/v1']);
    fakeOllamaServer();

    expect(doctor()[1])->toContain('port 11434 is on ollama, not on this machine');
});

it('warns when the round trip works but the server sends no exact counts', function () {
    Http::fake(function ($request) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/version' => Http::response(['version' => '0.40.14']),
            '/api/tags' => Http::response(['models' => [['name' => 'ledgerly-support:latest'], ['name' => 'nomic-embed-text:latest'], ['name' => 'llama3.2-vision:11b']]]),
            '/api/show' => Http::response(fakeShow($request['model'], 8192)),
            '/api/ps' => Http::response(['models' => []]),
            '/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'ready'], 'done' => true]),
            default => Http::response(chatBody('ready')),
        };
    });

    expect(doctor()[1])->toContain('⚠ /v1/chat/completions round-trip')->toContain('(no exact counts in the answer)');
});

it('fails when a chat endpoint errors', function () {
    fakeOllamaServer(['chat_ok' => false]);

    [$status, $output] = doctor();

    expect($status)->toBe(1)->and($output)->toContain('✗ round trip failed');
});

it('warns about a provider with no license recorded', function () {
    fakeOllamaServer();
    config(['ai.providers.local-70b.license' => null]);

    expect(doctor()[1])->toContain('⚠ license not recorded for local-70b');
});

it('checks the provider you name, by tier or by name, and refuses one that is hosted or not set up', function () {
    fakeOllamaServer();

    config(['ai.providers.local-70b.model' => 'ledgerly-support']);
    expect(doctor(['--provider' => 'mid'])[1])->toContain('Ollama at http://localhost:11434');

    config([
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'frontier-example',
    ]);
    [$status, $output] = doctor(['--provider' => 'frontier']);

    expect($status)->toBe(1)->and($output)->toContain("'frontier' is a hosted provider");

    config(['ai.providers.local-70b.model' => null]);
    [$status, $output] = doctor(['--provider' => 'mid']);

    expect($status)->toBe(1)->and($output)->toContain("The 'local-70b' provider is not set up");

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'frontier.example.com'));
});

describe('--health', function () {
    it('reports reachable, loaded and warm on one line', function () {
        fakeOllamaServer();

        [$status, $output] = doctor(['--health' => true]);

        expect($status)->toBe(0)
            ->and($output)->toContain('reachable: yes · loaded: yes · warm: yes (completion')
            ->and($output)->not->toContain('warnings');
    });

    it('says loaded: no when the model is not in memory yet, and still passes', function () {
        fakeOllamaServer(['loaded' => []]);

        [$status, $output] = doctor(['--health' => true]);

        expect($status)->toBe(0)->and($output)->toContain('loaded: no (it loads on the first request)');
    });

    it('fails when the model does not exist, or the server is down', function () {
        fakeOllamaServer(['models' => ['nomic-embed-text']]);

        [$status, $output] = doctor(['--health' => true]);

        expect($status)->toBe(1)->and($output)->toContain("the model 'ledgerly-support' does not exist");

        Http::fake(['*' => Http::failedConnection()]);

        [$status, $output] = doctor(['--health' => true]);

        expect($status)->toBe(1)->and($output)->toContain('reachable: no');
    });

    it('fails when the one-token completion errors', function () {
        fakeOllamaServer(['chat_ok' => false]);

        [$status, $output] = doctor(['--health' => true]);

        expect($status)->toBe(1)->and($output)->toContain('warm: no · a one-token completion failed');
    });
});
