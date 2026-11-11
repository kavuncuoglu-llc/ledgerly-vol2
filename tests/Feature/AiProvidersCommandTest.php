<?php

use App\Ai\Native\ExchangeRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    foreach (glob(base_path('storage/fixtures/openai-compatible/recorded-*.json')) as $file) {
        unlink($file);
    }
});

describe('ai:providers', function () {
    function providersOutput(): string
    {
        Artisan::call('ai:providers');

        return Artisan::output();
    }

    it('lists every provider with its driver, model, endpoint and capabilities', function () {
        expect(providersOutput())
            ->toContain('openai-compatible')
            ->toContain('llama3.1:8b')
            ->toContain('tools, structured, seed, ctx-override')
            ->toContain('embed (768)')
            ->toContain('llama3.2-vision:11b')
            ->toContain('rerank')
            ->toContain('everything');
    });

    it('says frontier is not set up, and that nothing is sent to it', function () {
        config(['ai.providers.frontier.base_url' => null, 'ai.providers.frontier.api_key' => null, 'ai.providers.frontier.model' => null]);

        expect(providersOutput())
            ->toContain('(not set)')
            ->toContain('Not set up: ')
            ->toContain('frontier')
            ->toContain('Nothing is sent to a provider that is not set up.');
    });

    it('shows frontier once FRONTIER_* is set, and never prints its key', function () {
        config([
            'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
            'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
            'ai.providers.frontier.model' => 'frontier-example',
        ]);

        expect(providersOutput())
            ->toContain('frontier-example')
            ->toContain('https://frontier.example.com/v1')
            ->not->toContain('sk-test-frontier-key')
            ->not->toContain('Not set up: frontier,')
            ->not->toContain('Not set up: frontier.');            // frontier-billing-ft (Chapter 41) is still unset: it is listed on its own
    });

    it('records the license and the date it was reviewed next to each model (Chapter 20, Topic 6)', function () {
        expect(providersOutput())->toContain('Llama 3.1 Community License (2026-11-02)');
    });
});

describe('ai:record', function () {
    beforeEach(function () {
        config([
            'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
            'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
            'ai.providers.frontier.model' => 'frontier-example',
        ]);

        $this->prompt = sys_get_temp_dir().'/prompt-'.bin2hex(random_bytes(3)).'.json';

        file_put_contents($this->prompt, json_encode(['messages' => [['role' => 'user', 'content' => 'What is a late fee?']]]));
    });

    afterEach(fn () => @unlink($this->prompt));

    it('saves the exchange for the conformance suite, and the key is nowhere in the file', function () {
        Http::fake(['frontier.example.com/*' => Http::response(chatBody('A late fee is a charge.'), 200, ['Content-Type' => 'application/json'])]);

        $this->artisan('ai:record', ['--provider' => 'frontier', '--fixture' => 'recorded-short', '--prompt' => $this->prompt])
            ->expectsOutputToContain('Recorded storage/fixtures/openai-compatible/recorded-short.json (request + response, key redacted)')
            ->assertSuccessful();

        $file = file_get_contents(base_path('storage/fixtures/openai-compatible/recorded-short.json'));

        expect($file)->not->toContain('sk-test-frontier-key')
            ->and(json_decode($file, true)['response']['body']['choices'][0]['message']['content'])->toBe('A late fee is a charge.')
            ->and(json_decode($file, true)['request']['body']['model'])->toBe('frontier-example');
    });

    it('refuses to record against a provider that is not set up', function () {
        config(['ai.providers.frontier.api_key' => null]);

        $this->artisan('ai:record', ['--provider' => 'frontier', '--fixture' => 'recorded-nope', '--prompt' => $this->prompt])
            ->expectsOutputToContain("The 'frontier' provider is not set up")
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('needs a fixture name and a prompt file', function () {
        $this->artisan('ai:record')->expectsOutputToContain('Give --fixture and --prompt.')->assertFailed();

        $this->artisan('ai:record', ['--fixture' => 'x', '--prompt' => '/nope.json'])->expectsOutputToContain('No prompt file')->assertFailed();
    });

    it('is in app/Ai/Native, the one place allowed to be provider-shaped', function () {
        expect((new ReflectionClass(ExchangeRecorder::class))->getFileName())->toContain('app/Ai/Native/');
    });
});
