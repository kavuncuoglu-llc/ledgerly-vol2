<?php

use App\Ai\Registry\ServeFineTune;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\RegistryWorld;

// Chapter 44, Topics 4, 8 and 9: serve:fine-tune puts the merged GGUF on an Ollama box and the adapter beside its base on vLLM, after the checks every server owes the registry.
beforeEach(function () {
    RegistryWorld::boot();

    $this->adapters = RegistryWorld::$directory.'/adapters';

    File::ensureDirectoryExists($this->adapters);

    config(['ai.registry.servers' => [
        'eu-billing-box' => ['kind' => 'ollama', 'url' => 'http://ollama.test:11434', 'host' => 'ollama.test:11434'],
        'gpu-eu-1' => ['kind' => 'vllm', 'url' => 'http://vllm.test:8000', 'api_key' => 'secret', 'base' => 'meta-llama/Llama-3.1-8B-Instruct', 'base_revision' => 'a1b2c3d', 'adapters_path' => $this->adapters, 'max_lora_rank' => 16],
    ]]);

    $this->entry = RegistryWorld::entry();
});

afterEach(function () {
    RegistryWorld::clean();
});

it('creates the merged model on the Ollama box from the verified file, and records the digest the box reports', function () {
    Process::fake(['*' => Process::result('success')]);

    Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'ledgerly-billing-ft-v1:latest', 'digest' => 'abc123']]])]);

    $lines = app(ServeFineTune::class)->serve($this->entry, ollama: 'eu-billing-box');

    expect($lines[0])->toContain('created ledgerly-billing-ft-v1 from Q4_K_M GGUF, digest sha256:abc123 recorded')
        ->and($this->entry->refresh()->served_on['eu-billing-box'])->toMatchArray(['kind' => 'ollama', 'model' => 'ledgerly-billing-ft-v1', 'digest' => 'sha256:abc123']);

    Process::assertRan(fn ($process) => array_slice($process->command, 0, 3) === ['ollama', 'create', 'ledgerly-billing-ft-v1'] && ($process->environment['OLLAMA_HOST'] ?? null) === 'ollama.test:11434');
});

it('does not create a model twice when the box already reports the recorded digest', function () {
    Process::fake(['*' => Process::result('success')]);

    Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'ledgerly-billing-ft-v1:latest', 'digest' => 'abc123']]])]);

    $serve = app(ServeFineTune::class);

    $serve->serve($this->entry, ollama: 'eu-billing-box');

    $lines = $serve->serve($this->entry->refresh(), ollama: 'eu-billing-box');

    expect($lines[0])->toContain('already loaded, digest sha256:abc123 unchanged');

    Process::assertRanTimes(fn ($process) => $process->command[1] === 'create', 1);
});

it('refuses to load a file that is not the file the registry recorded', function () {
    Process::fake();

    File::put($this->entry->artifacts['files']['gguf'], 'a different model');

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, ollama: 'eu-billing-box'))->toThrow(InvalidArgumentException::class, 'sha256 does not match the registry');

    Process::assertNothingRan();
});

it('refuses to load an unsigned file when signatures are required', function () {
    Process::fake();

    config(['ai.registry.signing.required' => true]);

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, ollama: 'eu-billing-box'))->toThrow(InvalidArgumentException::class, 'not signed');
});

it('will not create one version under the name of another, because that would replace the version rollback needs', function () {
    Process::fake();

    $second = RegistryWorld::entry('billing', 2, 'canary', ['model' => 'ledgerly-billing-ft-v1']);

    expect(fn () => app(ServeFineTune::class)->serve($second, ollama: 'eu-billing-box'))->toThrow(InvalidArgumentException::class, 'Export each version under its own name');
});

it('loads the adapter on vLLM beside the exact base it was trained on, over the API key', function () {
    Http::fake(['vllm.test:8000/v1/load_lora_adapter' => Http::response('Success', 200)]);

    $lines = app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1', adapterName: 'billing-v1');

    expect($lines[0])->toContain('adapter billing-v1 loaded beside meta-llama/Llama-3.1-8B-Instruct@a1b2c3d')
        ->and(file_exists($this->adapters.'/billing-v1/adapters.safetensors'))->toBeTrue()
        ->and($this->entry->refresh()->served_on['gpu-eu-1'])->toMatchArray(['kind' => 'vllm', 'adapter' => 'billing-v1']);

    Http::assertSent(fn ($request) => $request->url() === 'http://vllm.test:8000/v1/load_lora_adapter'
        && $request->hasHeader('Authorization', 'Bearer secret')
        && $request['lora_name'] === 'billing-v1'
        && $request['lora_path'] === $this->adapters.'/billing-v1');
});

it('refuses a vLLM server that has no API key', function () {
    config(['ai.registry.servers.gpu-eu-1.api_key' => '']);

    Http::fake();

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'has no API key');

    Http::assertNothingSent();
});

it('refuses a base or a revision that is not the one the adapter was trained on', function (string $setting, ?string $value, string $message) {
    config(["ai.registry.servers.gpu-eu-1.{$setting}" => $value]);

    Http::fake();

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'another model' => ['base', 'Qwen/Qwen2.5-7B-Instruct', 'An adapter works only on its exact base'],
    'another revision' => ['base_revision', 'ffffff0', 'a different revision is a different base'],
    'no revision in config' => ['base_revision', null, '@<no revision in config>'],
]);

it('refuses an adapter whose own config names another base, or whose rank the server would refuse', function () {
    Http::fake();

    $directory = dirname($this->entry->artifacts['files']['adapter']);

    File::put($directory.'/adapter_config.json', json_encode(['base_model_name_or_path' => 'Qwen/Qwen2.5-7B-Instruct', 'r' => 16]));

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'adapter_config.json says it was trained on Qwen/Qwen2.5-7B-Instruct');

    File::put($directory.'/adapter_config.json', json_encode(['base_model_name_or_path' => 'meta-llama/Llama-3.1-8B-Instruct', 'r' => 64]));

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'rank 64; the server was started with --max-lora-rank 16');

    File::delete($directory.'/adapter_config.json');

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'No adapter_config.json');

    Http::assertNothingSent();
});

it('records no base revision as a reason not to serve an adapter', function () {
    Http::fake();

    $this->entry->update(['base_revision' => null]);

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'records no base revision');
});

it('surfaces a vLLM refusal instead of recording the adapter as loaded', function () {
    Http::fake(['vllm.test:8000/*' => Http::response('adapter rank too high', 400)]);

    expect(fn () => app(ServeFineTune::class)->serve($this->entry, vllm: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'did not load billing-v1: adapter rank too high')
        ->and($this->entry->refresh()->served_on)->toBeNull();
});

it('asks for a server, knows only the servers in config, and does not serve a rejected version', function () {
    $serve = app(ServeFineTune::class);

    expect(fn () => $serve->serve($this->entry))->toThrow(InvalidArgumentException::class, 'Name a server')
        ->and(fn () => $serve->serve($this->entry, ollama: 'nowhere'))->toThrow(InvalidArgumentException::class, 'There is no server [nowhere]')
        ->and(fn () => $serve->serve($this->entry, ollama: 'gpu-eu-1'))->toThrow(InvalidArgumentException::class, 'is a vllm, not an ollama server');

    $this->entry->update(['stage' => 'rejected']);

    expect(fn () => $serve->serve($this->entry, ollama: 'eu-billing-box'))->toThrow(InvalidArgumentException::class, 'is rejected: it is not served');
});

it('unloads a version from every server it was put on, and loads it again from its archive', function () {
    Process::fake(['*' => Process::result('success')]);

    Http::fake([
        'ollama.test:11434/api/delete' => Http::response('', 200),
        'ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'ledgerly-billing-ft-v1:latest', 'digest' => 'abc123']]]),
    ]);

    $serve = app(ServeFineTune::class);

    $serve->serve($this->entry, ollama: 'eu-billing-box');

    $serve->unload($this->entry->refresh());

    expect($this->entry->refresh()->served_on['eu-billing-box'])->toHaveKey('unloaded_at');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'http://ollama.test:11434/api/delete' && $request['model'] === 'ledgerly-billing-ft-v1');

    $serve->reload($this->entry);

    Process::assertRanTimes(fn ($process) => $process->command[1] === 'create', 2);
});

it('serves through its command and prints what it did', function () {
    Process::fake(['*' => Process::result('success')]);

    Http::fake(['ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'ledgerly-billing-ft-v1:latest', 'digest' => 'abc123']]])]);

    $this->artisan('serve:fine-tune', ['name' => 'billing', 'version' => 1, '--ollama' => 'eu-billing-box'])
        ->expectsOutputToContain('ollama eu-billing-box: created ledgerly-billing-ft-v1')
        ->assertExitCode(0);

    $this->artisan('serve:fine-tune', ['name' => 'billing', 'version' => 1])->expectsOutputToContain('Name a server')->assertExitCode(1);

    expect(ModelRegistryEntry::query()->count())->toBe(1);
});
