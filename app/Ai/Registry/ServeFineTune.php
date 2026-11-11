<?php

namespace App\Ai\Registry;

use App\Models\ModelRegistryEntry;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

// Chapter 44, Topic 4 and Figure 44-12: `serve:fine-tune` puts a registered version on the servers that answer for it. The merged GGUF goes to an Ollama box (`ollama create`); the adapter goes to a vLLM server beside its base (`POST /v1/load_lora_adapter`).
// Before either, it checks what Topics 8 and 9 ask for: the file on disk is the file the registry recorded (and signed), the server has an API key where it can have one, and for an adapter, the base the server loaded is the exact base the adapter was trained on.
class ServeFineTune
{
    public function __construct(private ArtifactVerifier $verifier) {}

    /** @return list<string> what was done, one line each */
    public function serve(ModelRegistryEntry $entry, ?string $ollama = null, ?string $vllm = null, ?string $adapterName = null): array
    {
        if ($ollama === null && $vllm === null) {
            throw new InvalidArgumentException('Name a server: --ollama=<name> for the merged model, --vllm=<name> for the adapter (config ai.registry.servers).');
        }

        if (in_array($entry->stage, ['retired', 'rejected'], true)) {
            throw new InvalidArgumentException("{$entry->label()} is {$entry->stage}: it is not served. Roll back to it with registry:rollback --to={$entry->version} if that is the intent.");
        }

        $lines = [];

        if ($ollama !== null) {
            $lines[] = $this->toOllama($entry, $ollama);
        }

        if ($vllm !== null) {
            $lines[] = $this->toVllm($entry, $vllm, $adapterName ?? "{$entry->name}-v{$entry->version}");
        }

        return $lines;
    }

    // A retired version is served again from its archived artifacts: the slow way back (Exercise 4).
    public function reload(ModelRegistryEntry $entry): void
    {
        foreach ((array) $entry->served_on as $server => $served) {
            if (($served['kind'] ?? '') === 'ollama') {
                $this->toOllama($entry, $server, force: true);
            }

            if (($served['kind'] ?? '') === 'vllm') {
                $this->toVllm($entry, $server, (string) $served['adapter']);
            }
        }
    }

    public function unload(ModelRegistryEntry $entry): void
    {
        $served = (array) $entry->served_on;

        foreach ($served as $server => $record) {
            if (isset($record['unloaded_at'])) {
                continue;
            }

            $config = $this->server($server);

            if (($record['kind'] ?? '') === 'ollama') {
                $this->ollama($config)->send('DELETE', '/api/delete', ['json' => ['model' => $entry->model]]);
            }

            if (($record['kind'] ?? '') === 'vllm') {
                $this->vllm($config)->post('/v1/unload_lora_adapter', ['lora_name' => $record['adapter']]);
            }

            $served[$server]['unloaded_at'] = now()->toIso8601String();
        }

        $entry->update(['served_on' => $served === [] ? null : $served]);
    }

    // `ai:doctor --local`'s check for a self-served model (Chapter 44, Topic 8): the digest the server reports for the model now, against the digest recorded when it was loaded.
    public function digestFor(ModelRegistryEntry $entry, string $server): ?string
    {
        $tags = $this->ollama($this->server($server))->get('/api/tags')->json('models') ?? [];

        foreach ($tags as $model) {
            if (in_array($model['name'] ?? '', [$entry->model, $entry->model.':latest'], true)) {
                return 'sha256:'.str_replace('sha256:', '', (string) ($model['digest'] ?? ''));
            }
        }

        return null;
    }

    private function toOllama(ModelRegistryEntry $entry, string $serverName, bool $force = false): string
    {
        $server = $this->server($serverName, 'ollama');

        $this->verifier->assertLoadable($entry, 'gguf');

        $this->refuseAnotherVersionsName($entry);

        $recorded = isset($entry->served_on[$serverName]['unloaded_at'])
            ? null
            : ($entry->served_on[$serverName]['digest'] ?? null);

        if (! $force && $recorded !== null && $this->digestFor($entry, $serverName) === $recorded) {
            return "ollama {$serverName}: {$entry->model} already loaded, digest {$recorded} unchanged";
        }

        $gguf = $this->verifier->files($entry)['gguf'];

        $directory = sys_get_temp_dir().'/serve-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($directory);

        File::put($directory.'/Modelfile', "FROM {$gguf}\nPARAMETER num_ctx ".(int) config('ai.registry.num_ctx', 8192)."\nPARAMETER temperature 0\n");

        $result = Process::env(array_filter(['OLLAMA_HOST' => $server['host'] ?? null]))->timeout(1800)->run([config('ai.registry.ollama_binary', 'ollama'), 'create', $entry->model, '-f', $directory.'/Modelfile']);

        File::deleteDirectory($directory);

        if ($result->failed()) {
            throw new InvalidArgumentException("ollama create {$entry->model} failed on {$serverName}: ".trim($result->errorOutput()));
        }

        $digest = $this->digestFor($entry, $serverName);

        $this->remember($entry, $serverName, ['kind' => 'ollama', 'model' => $entry->model, 'digest' => $digest, 'at' => now()->toIso8601String()]);

        return "ollama {$serverName}: created {$entry->model} from {$entry->artifacts['quantization']} GGUF, digest {$digest} recorded";
    }

    private function toVllm(ModelRegistryEntry $entry, string $serverName, string $adapterName): string
    {
        $server = $this->server($serverName, 'vllm');

        if (blank($server['api_key'] ?? null)) {
            throw new InvalidArgumentException("The vLLM server {$serverName} has no API key in config (ai.registry.servers.{$serverName}.api_key): it does not serve without one, even on the internal network.");
        }

        $this->verifier->assertLoadable($entry, 'adapter');

        $this->assertSameBase($entry, $server, $serverName);

        $source = dirname($this->verifier->files($entry)['adapter']);

        $configuration = json_decode((string) @file_get_contents($source.'/adapter_config.json'), true);

        $this->assertAdapterConfiguration($entry, $server, is_array($configuration) ? $configuration : null);

        $path = rtrim((string) ($server['adapters_path'] ?? ''), '/').'/'.$adapterName;

        $this->place($source, $path);

        $response = $this->vllm($server)->post('/v1/load_lora_adapter', ['lora_name' => $adapterName, 'lora_path' => $path]);

        if ($response->failed()) {
            throw new InvalidArgumentException("vLLM {$serverName} did not load {$adapterName}: ".trim($response->body()));
        }

        $this->remember($entry, $serverName, ['kind' => 'vllm', 'adapter' => $adapterName, 'path' => $path, 'at' => now()->toIso8601String()]);

        return "vllm {$serverName}: adapter {$adapterName} loaded beside {$server['base']}@{$server['base_revision']}; chosen per request by \"model\": \"{$adapterName}\"";
    }

    private function assertSameBase(ModelRegistryEntry $entry, array $server, string $serverName): void
    {
        if (($server['base'] ?? null) !== $entry->base) {
            throw new InvalidArgumentException("{$entry->label()} was trained on {$entry->base}; {$serverName} serves ".($server['base'] ?? '<no base in config>').'. An adapter works only on its exact base.');
        }

        if (blank($entry->base_revision)) {
            throw new InvalidArgumentException("{$entry->label()} records no base revision, so the base {$serverName} loaded cannot be checked against it: registry:add --base-revision.");
        }

        if (($server['base_revision'] ?? null) !== $entry->base_revision) {
            throw new InvalidArgumentException("{$entry->label()} was trained on {$entry->base}@{$entry->base_revision}; {$serverName} loaded @".($server['base_revision'] ?? '<no revision in config>').'. Same model, a different revision is a different base.');
        }
    }

    private function assertAdapterConfiguration(ModelRegistryEntry $entry, array $server, ?array $configuration): void
    {
        if ($configuration === null) {
            throw new InvalidArgumentException("No adapter_config.json beside the adapter of {$entry->label()}: the base it names and its rank cannot be checked.");
        }

        if (($configuration['base_model_name_or_path'] ?? null) !== $entry->base) {
            throw new InvalidArgumentException("The adapter's own adapter_config.json says it was trained on ".($configuration['base_model_name_or_path'] ?? '<nothing>').", and the registry says {$entry->base}.");
        }

        $rank = (int) ($configuration['r'] ?? 0);

        if ($rank > (int) ($server['max_lora_rank'] ?? 16)) {
            throw new InvalidArgumentException("The adapter has rank {$rank}; the server was started with --max-lora-rank ".($server['max_lora_rank'] ?? 16).' and would refuse it.');
        }
    }

    // A model name belongs to one version: creating v2 under v1's name would replace v1 while the alias still points at it. Rollback is only instant if the old version is still there.
    private function refuseAnotherVersionsName(ModelRegistryEntry $entry): void
    {
        $other = ModelRegistryEntry::query()->where('model', $entry->model)->whereKeyNot($entry->id)->whereNotIn('stage', ['retired', 'rejected'])->first();

        if ($other !== null) {
            throw new InvalidArgumentException("{$other->label()} is served as {$entry->model} already. Export each version under its own name (ledgerly-billing-ft-v{$entry->version}) so both can be loaded.");
        }
    }

    // The adapter directory is copied where the server reads it from, when that is a directory this machine can write (a mount); a remote server's directory is filled by whatever syncs it.
    private function place(string $source, string $path): void
    {
        if (is_dir($path) && realpath($path) === realpath($source)) {
            return;
        }

        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            return;
        }

        File::ensureDirectoryExists($path);

        foreach (['adapter_config.json', 'adapter_model.safetensors', 'adapters.safetensors'] as $file) {
            if (is_file($source.'/'.$file)) {
                File::copy($source.'/'.$file, $path.'/'.$file);
            }
        }
    }

    private function remember(ModelRegistryEntry $entry, string $server, array $served): void
    {
        $entry->update(['served_on' => [...(array) $entry->served_on, $server => $served]]);
    }

    private function server(string $name, ?string $kind = null): array
    {
        $server = config('ai.registry.servers')[$name] ?? throw new InvalidArgumentException("There is no server [{$name}] in config ai.registry.servers.");

        if ($kind !== null && ($server['kind'] ?? null) !== $kind) {
            throw new InvalidArgumentException("[{$name}] is a ".($server['kind'] ?? 'server of no kind').", not an {$kind} server.");
        }

        return $server;
    }

    private function ollama(array $server): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $server['url'], '/'))->timeout(30)->acceptJson();
    }

    private function vllm(array $server): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $server['url'], '/'))->timeout(120)->acceptJson()->withToken((string) $server['api_key']);
    }
}
