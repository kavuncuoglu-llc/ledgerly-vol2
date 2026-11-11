<?php

namespace App\Ai\Doctor;

use App\Ai\Hardware\HostProbe;
use Closure;

// Chapter 25, Topic 9: `ai:doctor --profile=vllm` checks the Compose profile you mean to run against the machine you are on, before you start it: the GPU, the container runtime, the variables the profile refuses to start without, the files it mounts and the port it publishes.
// Nothing here starts a container. Everything the machine says goes through HostProbe, so a test can describe a Mac, a GPU server or a laptop.
final class ProfileChecks
{
    public const PROFILES = ['ollama', 'llama', 'vllm', 'triton', 'monitoring'];

    private const GPU_QUERY = 'nvidia-smi --query-gpu=name,memory.total,compute_cap --format=csv,noheader,nounits';

    /** @param Closure(string): string $env a variable from .env; @param Closure(int): bool $portInUse */
    public function __construct(
        private HostProbe $host,
        private Closure $env,
        private Closure $portInUse,
        private string $basePath,
    ) {}

    public function title(string $profile): string
    {
        return "Compose profile '{$profile}' on this machine ({$this->host->os()}".$this->gpuSummary().')';
    }

    /** @return list<Check> */
    public function run(string $profile): array
    {
        return array_values(array_filter([
            $this->docker(),
            ...match ($profile) {
                'vllm' => [...$this->nvidiaProfile('vllm'), ...$this->variables(['VLLM_API_KEY', 'VLLM_REVISION', 'VLLM_MODEL']), $this->port('VLLM_PORT', 8001), $this->fp8(), $this->composeFile('vllm.yml')],
                'triton' => [...$this->nvidiaProfile('triton'), ...$this->variables(['TRITON_API_KEY']), $this->port('TRITON_PORT', 9000), $this->tritonRepository(), $this->composeFile('triton.yml')],
                'llama' => [...$this->llama(), ...$this->variables(['LLAMA_API_KEY']), $this->port('LLAMA_PORT', 8080), $this->composeFile('llama.yml')],
                'ollama' => [...$this->ollama(), $this->port('OLLAMA_PORT', 11434), $this->composeFile('ollama.yml')],
                'monitoring' => [...$this->variables(['GRAFANA_ADMIN_PASSWORD']), $this->port('GRAFANA_PORT', 3000), $this->composeFile('monitoring.yml')],
                default => [Check::fail("Unknown profile '{$profile}'. Use one of: ".implode(', ', self::PROFILES).'.')],
            },
        ]));
    }

    private function docker(): Check
    {
        if ($this->host->run('docker compose version') === '') {
            return Check::fail('Docker Compose is not available (`docker compose version` printed nothing). Install Docker with the compose plugin.');
        }

        return Check::ok('docker compose '.trim(str_replace('Docker Compose version', '', $this->host->run('docker compose version'))));
    }

    /** @return list<Check> */
    private function nvidiaProfile(string $profile): array
    {
        $gpus = $this->gpus();

        if ($gpus === []) {
            return [Check::fail("no NVIDIA GPU found (`nvidia-smi` printed nothing): the {$profile} profile cannot start here. Use the ollama or llama profile, or rent a GPU for an hour (Appendix L.7: ops/cloud/gpu-session.sh).")];
        }

        $checks = [];

        foreach ($gpus as $index => $gpu) {
            $checks[] = $gpu['memory_gb'] >= 22                                          // a "24 GB" L4, A10 or 4090 reports 22.5 to 24 GiB
                ? Check::ok(sprintf('GPU %d: %s, %.0f GB: room for the 8B at FP8 with a full-window cache for ~25 sequences', $index, $gpu['name'], $gpu['memory_gb']))
                : ($gpu['memory_gb'] >= 10
                    ? Check::warn(sprintf('GPU %d: %s, %.0f GB: the 8B at FP8 fits (8.5 GB of weights) but the cache is small. Lower VLLM_MAX_MODEL_LEN or VLLM_MAX_NUM_SEQS, and run `ai:doctor --provider=vllm` after the start to read the capacity.', $index, $gpu['name'], $gpu['memory_gb']))
                    : Check::fail(sprintf('GPU %d: %s, %.0f GB: too small for the 8B at FP8 (8.5 GB of weights plus a cache). Use an AWQ 4-bit model (5.5 GB) or the llama profile.', $index, $gpu['name'], $gpu['memory_gb'])));
        }

        $checks[] = str_contains($this->host->run("docker info --format '{{json .Runtimes}}'"), 'nvidia')
            ? Check::ok('Docker has the nvidia runtime (nvidia-container-toolkit is installed and configured)')
            : Check::fail('Docker has no nvidia runtime: install nvidia-container-toolkit and run `sudo nvidia-ctk runtime configure --runtime=docker && sudo systemctl restart docker`. Without it the GPU reservation in the compose file cannot be honoured.');

        return $checks;
    }

    // Native FP8 math arrives with compute capability 8.9 (Ada) and 9.0 (Hopper); older cards emulate it, which works but is slower (Figure 24-4).
    private function fp8(): ?Check
    {
        $gpus = $this->gpus();

        if ($gpus === []) {
            return null;
        }

        $oldest = min(array_column($gpus, 'compute'));

        return $oldest >= 8.9
            ? Check::ok(sprintf('compute capability %.1f: native FP8 math', $oldest))
            : Check::warn(sprintf('compute capability %.1f has no native FP8: vLLM emulates it (works, slower). An AWQ 4-bit model is the faster choice on this card (Figure 24-4).', $oldest));
    }

    /** @return list<Check> */
    private function llama(): array
    {
        $checks = [];

        $file = ($this->env)('LLAMA_MODEL_FILE') ?: 'ledgerly-support-q4_k_m.gguf';

        $checks[] = $this->host->glob($this->basePath.'/models/'.$file) !== []
            ? Check::ok("models/{$file} is in place")
            : Check::fail("models/{$file} is missing: run ./ops/llama/pull.sh (it checks every file against a pinned SHA-256).");

        if (($this->env)('LLAMA_VARIANT') === 'cuda') {
            array_push($checks, ...$this->nvidiaProfile('llama (cuda)'));
        }

        return $checks;
    }

    /** @return list<Check> */
    private function ollama(): array
    {
        if ($this->host->os() === 'Darwin') {
            return [Check::warn('Docker on a Mac cannot use Metal: a containerised Ollama runs on the CPU. Run Ollama natively and use `-f ops/compose/macos.override.yml` with no inference profile (Chapter 22).')];
        }

        return $this->gpus() === []
            ? [Check::ok('no NVIDIA GPU: Ollama will run on the CPU (add -f ops/compose/ollama.gpu.yml only with one)')]
            : [Check::ok('NVIDIA GPU present: add -f ops/compose/ollama.gpu.yml to use it')];
    }

    private function tritonRepository(): Check
    {
        $missing = [];

        foreach (['reranker', 'embedder'] as $model) {
            if ($this->host->glob($this->basePath."/ops/triton/models/{$model}/1/model.onnx") === []) {
                $missing[] = $model;
            }
        }

        return $missing === []
            ? Check::ok('ops/triton/models has the ONNX files for the reranker and the embedder')
            : Check::warn('ops/triton/models/'.implode(' and ', $missing).'/1/model.onnx is not there (400 MB each, not in git): run ops/triton/export_onnx.py. Triton starts without them and reports those models unavailable.');
    }

    /** @param list<string> $names @return list<Check> */
    private function variables(array $names): array
    {
        $checks = [];

        foreach ($names as $name) {
            $checks[] = (($this->env)($name) ?? '') !== ''
                ? Check::ok("{$name} is set in .env")
                : Check::fail("{$name} is empty in .env: Compose refuses to start this profile without it.");
        }

        return $checks;
    }

    private function port(string $variable, int $default): Check
    {
        $port = (int) (($this->env)($variable) ?: $default);

        return ($this->portInUse)($port)
            ? Check::warn("port {$port} on 127.0.0.1 is already in use ({$variable}): set another port in .env, or stop what has it")
            : Check::ok("port {$port} on 127.0.0.1 is free ({$variable})");
    }

    private function composeFile(string $file): Check
    {
        return $this->host->glob($this->basePath.'/ops/compose/'.$file) !== []
            ? Check::ok("ops/compose/{$file} exists")
            : Check::fail("ops/compose/{$file} does not exist in this checkout");
    }

    private function gpuSummary(): string
    {
        $gpus = $this->gpus();

        if ($gpus === []) {
            return ', no NVIDIA GPU';
        }

        return sprintf(', %d × %s, %.0f GB', count($gpus), $gpus[0]['name'], $gpus[0]['memory_gb']);
    }

    /** @return list<array{name: string, memory_gb: float, compute: float}> */
    private function gpus(): array
    {
        $gpus = [];

        foreach (preg_split('/\R/', $this->host->run(self::GPU_QUERY)) ?: [] as $line) {
            $parts = array_map('trim', explode(',', $line));

            if (count($parts) < 3 || $parts[0] === '') {
                continue;
            }

            $gpus[] = ['name' => $parts[0], 'memory_gb' => (float) $parts[1] / 1024, 'compute' => (float) $parts[2]];
        }

        return $gpus;
    }
}
