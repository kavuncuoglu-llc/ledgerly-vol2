<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Chapter 43, Topic 6: `gpu-session.sh train`, run for real with the local provider: no machine, no money. The manifest check and the ledger are stubs that write what they were asked to a file, so each test reads what the session did.
function gpuSession(array $arguments, array $env = []): ProcessResult
{
    $dir = test()->dir;

    return Process::path(base_path())->env($env + [
        'GPU_SESSION_LOCAL_ALLOW_UNENCRYPTED' => '1',
        'GPU_SESSION_POLL_SECONDS' => '1',
        'GPU_SESSION_RECEIPTS' => "{$dir}/receipts",
        'GPU_SESSION_LEDGER_CMD' => "bash {$dir}/ledger.sh",
        'GPU_SESSION_ALLOWS_CMD' => "bash {$dir}/allows.sh",
        'GPU_SESSION_TRAIN_CMD' => 'mkdir -p "$VOLUME/results" && echo adapter > "$VOLUME/results/adapter.safetensors"',
    ])->timeout(60)->run(['bash', base_path('ops/cloud/gpu-session.sh'), 'train', ...$arguments]);
}

function gpuArguments(array $override = []): array
{
    $dir = test()->dir;

    $options = $override + ['--provider' => 'local', '--region' => 'eu', '--gpu' => 'a100-80gb', '--dataset' => 'billing-synthetic@a41c09e', '--config' => base_path('training/configs/axolotl/billing-qlora.yml'), '--max-hours' => '0.01', '--idle-stop' => '10m', '--results' => "{$dir}/results"];

    return collect($options)->filter(fn ($value) => $value !== null)->map(fn ($value, $key) => $value === true ? $key : "{$key}={$value}")->values()->all();
}

beforeEach(function () {
    if (Process::run('command -v bash')->failed()) {
        $this->markTestSkipped('bash is not available');
    }

    $this->dir = sys_get_temp_dir().'/gpu-session-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    File::put("{$this->dir}/allows.sh", "echo \"allows \$*\" >> {$this->dir}/calls.log\nexit \${ALLOWS_EXIT:-0}\n");

    File::put("{$this->dir}/ledger.sh", "echo \"ledger \$*\" >> {$this->dir}/calls.log\n");
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('runs a session end to end: the manifest check, a volume, the training, the results pushed, the volume destroyed, a receipt and two ledger calls', function () {
    $result = gpuSession(gpuArguments(), ['GPU_SESSION_RATE_USD_PER_HOUR' => '2.50']);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($result->output())->toContain('manifest allows provider and region: yes')
        ->and($result->output())->toContain('encrypted volume created')
        ->and($result->output())->toContain('ledger: training session row opened')
        ->and($result->output())->toContain('run finished · adapter and MLflow run pushed')
        ->and($result->output())->toContain('volume destroyed · machine stopped');

    expect(File::get("{$this->dir}/results/adapter.safetensors"))->toBe("adapter\n");

    $calls = File::get("{$this->dir}/calls.log");

    expect($calls)->toContain('allows billing-synthetic@a41c09e --provider=local --region=eu')
        ->and($calls)->toContain('ledger open --session=')
        ->and($calls)->toContain('ledger close --session=')
        ->and($calls)->toContain('--rate=2.50')
        ->and($calls)->toContain('--note=finished');

    $receipt = json_decode(File::get(File::files("{$this->dir}/receipts")[0]->getPathname()), true);

    expect($receipt)->toMatchArray(['provider' => 'local', 'region' => 'eu', 'gpu' => 'a100-80gb', 'status' => 'finished', 'dataset' => 'billing-synthetic@a41c09e']);
});

it('refuses before anything is created when the manifest does not allow the provider and region', function () {
    $result = gpuSession(gpuArguments(), ['ALLOWS_EXIT' => '1']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('refused: the manifest of billing-synthetic@a41c09e does not allow local in eu. Nothing was created.')
        ->and(File::exists("{$this->dir}/receipts"))->toBeFalse()
        ->and(File::get("{$this->dir}/calls.log"))->not->toContain('ledger');
});

it('refuses a volume that is not encrypted', function () {
    $result = gpuSession(gpuArguments(), ['GPU_SESSION_LOCAL_ALLOW_UNENCRYPTED' => '0']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('is not encrypted: refusing to put training data on it');
});

it('stops at the hard time limit whatever the run is doing, and says so on the receipt', function () {
    $result = gpuSession(gpuArguments(['--max-hours' => '0.0008']), ['GPU_SESSION_TRAIN_CMD' => 'sleep 30']);

    expect($result->exitCode())->toBe(124)
        ->and($result->output())->toContain('hard time limit reached: stopping the run')
        ->and($result->output())->toContain('volume destroyed · machine stopped');

    expect(File::get("{$this->dir}/calls.log"))->toContain('--note=stopped at the time limit');
});

it('stops when the GPU has been idle, which catches a finished job, a crash and a forgotten notebook alike', function () {
    File::put("{$this->dir}/utilization", '0');

    $result = gpuSession(gpuArguments(['--idle-stop' => '2s']), ['GPU_SESSION_TRAIN_CMD' => 'sleep 30', 'GPU_SESSION_FAKE_UTILIZATION_FILE' => "{$this->dir}/utilization"]);

    expect($result->exitCode())->toBe(125)
        ->and($result->output())->toContain('the GPU has been idle for 2s: stopping the run and the machine')
        ->and(File::get("{$this->dir}/calls.log"))->toContain('--note=stopped idle');
});

it('does not stop a busy GPU', function () {
    File::put("{$this->dir}/utilization", '97');

    $result = gpuSession(gpuArguments(['--idle-stop' => '2s']), ['GPU_SESSION_TRAIN_CMD' => 'sleep 4', 'GPU_SESSION_FAKE_UTILIZATION_FILE' => "{$this->dir}/utilization"]);

    expect($result->successful())->toBeTrue($result->errorOutput())->and($result->output())->not->toContain('has been idle');
});

it('cleans up after a crash: the volume destroyed, the machine stopped, the ledger row closed, the exit code kept', function () {
    $result = gpuSession(gpuArguments(), ['GPU_SESSION_TRAIN_CMD' => 'exit 3']);

    expect($result->exitCode())->toBe(3)
        ->and($result->output())->toContain('volume destroyed · machine stopped');

    expect(File::get("{$this->dir}/calls.log"))->toContain('--note=failed (exit 3)');
});

it('resumes on a new machine when an interruptible instance is reclaimed, and gives up after its retries', function () {
    File::put("{$this->dir}/counter", '0');

    $command = "n=\$(cat {$this->dir}/counter); echo \$((n + 1)) > {$this->dir}/counter; if [ \"\$n\" -lt 1 ]; then exit 75; fi; mkdir -p \"\$VOLUME/results\"; echo ok > \"\$VOLUME/results/adapter.safetensors\"";

    $result = gpuSession(gpuArguments(['--interruptible' => true]), ['GPU_SESSION_TRAIN_CMD' => $command]);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($result->output())->toContain('the instance was reclaimed: resuming from the last checkpoint on a new machine (1 of 2)')
        ->and(File::get("{$this->dir}/results/adapter.safetensors"))->toBe("ok\n");

    $always = gpuSession(gpuArguments(['--interruptible' => true, '--retries' => '1']), ['GPU_SESSION_TRAIN_CMD' => 'exit 75']);

    expect($always->exitCode())->toBe(75);
});

it('needs a time limit, a place for the results and a provider script', function () {
    expect(gpuSession(gpuArguments(['--max-hours' => null, '--results' => null]))->errorOutput())->toContain('a session needs: --max-hours --results')
        ->and(gpuSession(gpuArguments(['--provider' => 'nowhere']))->errorOutput())->toContain('no provider script at')
        ->and(gpuSession(gpuArguments(['--bogus' => true]))->exitCode())->toBe(2);
});

it('passes shellcheck with its provider scripts', function () {
    if (Process::run('command -v shellcheck')->failed()) {
        $this->markTestSkipped('shellcheck is not installed');
    }

    $result = Process::run(['shellcheck', base_path('ops/cloud/gpu-session.sh'), base_path('ops/cloud/providers/local.sh'), base_path('ops/cloud/providers/template.sh')]);

    expect($result->successful())->toBeTrue($result->output());
});
