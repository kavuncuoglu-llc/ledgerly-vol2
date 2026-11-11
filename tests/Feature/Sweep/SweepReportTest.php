<?php

use App\Evals\Sweep\BestSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

// Chapter 43, Topic 7: a sweep read from MLflow, ranked on the golden set, the winner held to the tripwire and the injection suite, its settings written into the production configs.
function sweepRun(string $name, array $metrics, string $id = 'r'): array
{
    return [
        'info' => ['run_id' => $id.substr(md5($name), 0, 6), 'run_name' => $name],
        'data' => ['metrics' => array_map(fn ($key, $value) => ['key' => $key, 'value' => $value], array_keys($metrics), $metrics), 'params' => [], 'tags' => []],
    ];
}

beforeEach(function () {
    config(['services.mlflow.tracking_uri' => 'http://mlflow.test']);

    $this->dir = sys_get_temp_dir().'/sweep-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    $runs = [
        ['lr0.0002_r16_ep2', 0.79, 0.975, 0.986, 1.1, 0.12],
        ['lr0.0001_r16_ep2', 0.83, 0.970, 0.981, 0.6, 0.12],
        ['lr0.0002_r8_ep2', 0.81, 0.968, 0.979, 0.9, 0.13],
        ['lr0.0004_r16_ep2', 0.74, 0.961, 0.972, 2.8, 0.16],
        ['lr0.0002_r16_ep2_seed7', 0.80, 0.972, 0.980, 1.0, 0.12],
    ];

    $this->manifest = $this->dir.'/sweep.json';

    File::put($this->manifest, json_encode(['runs' => [
        ['name' => 'lr0.0002_r16_ep2', 'params' => ['learning_rate' => 0.0002, 'lora_r' => 16, 'num_epochs' => 2], 'seed' => 42, 'center_repeat' => false],
        ['name' => 'lr0.0001_r16_ep2', 'params' => ['learning_rate' => 0.0001, 'lora_r' => 16, 'num_epochs' => 2], 'seed' => 42, 'center_repeat' => false],
        ['name' => 'lr0.0002_r8_ep2', 'params' => ['learning_rate' => 0.0002, 'lora_r' => 8, 'num_epochs' => 2], 'seed' => 42, 'center_repeat' => false],
        ['name' => 'lr0.0004_r16_ep2', 'params' => ['learning_rate' => 0.0004, 'lora_r' => 16, 'num_epochs' => 2], 'seed' => 42, 'center_repeat' => false],
        ['name' => 'lr0.0002_r16_ep2_seed7', 'params' => ['learning_rate' => 0.0002, 'lora_r' => 16, 'num_epochs' => 2], 'seed' => 7, 'center_repeat' => true],
    ]]));

    $mlflowRuns = array_map(fn (array $r) => sweepRun($r[0], ['eval_loss' => $r[1], 'golden_pass_rate' => $r[2], 'required_facts_rate' => $r[3], 'tripwire_generation_largest_drop' => $r[4], 'injection_model_success_rate' => $r[5]]), $runs);

    $mlflowRuns[] = sweepRun('lr0.0001_r8_ep1', ['eval_loss' => 0.9]);

    Http::fake([
        'mlflow.test/api/2.0/mlflow/experiments/get-by-name*' => Http::response(['experiment' => ['experiment_id' => '9']]),
        'mlflow.test/api/2.0/mlflow/runs/search' => Http::response(['runs' => $mlflowRuns]),
    ]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('ranks on the golden set, rejects the lowest-loss run that fails the tripwire and injection, and selects the best that passes', function () {
    $this->artisan('eval:sweep-report', ['experiment' => 'mlflow:billing-sweep', '--manifest' => $this->manifest, '--base-injection' => '0.13'])
        ->expectsOutputToContain('mlflow:billing-sweep · 6 runs · ranked by golden pass rate on golden:billing')
        ->expectsOutputToContain('← selected')
        ->expectsOutputToContain('← lowest loss, rejected (tripwire -2.8, injection 16%)')
        ->expectsOutputToContain('lr0.0001_r8_ep1: not scored')
        ->expectsOutputToContain('center point repeated: seed spread ±0.15 points')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'runs/search') && $request['experiment_ids'] === ['9']);
});

it('refuses to check injection without the base rate to hold a run to', function () {
    $this->artisan('eval:sweep-report', ['experiment' => 'mlflow:billing-sweep', '--manifest' => $this->manifest])
        ->expectsOutputToContain('--then=injection needs --base-injection')
        ->assertExitCode(1);
});

it('writes the winner\'s learning rate, rank and epochs into both production configs and recomputes the iterations', function () {
    foreach (['billing-peft.yaml', 'billing-mlx.yaml'] as $file) {
        File::copy(base_path("training/configs/{$file}"), "{$this->dir}/{$file}");
    }

    $this->artisan('eval:sweep-report', ['experiment' => 'mlflow:billing-sweep', '--manifest' => $this->manifest, '--base-injection' => '0.14', '--write-best' => $this->dir, '--rows' => '130'])
        ->expectsOutputToContain('billing-mlx.yaml: ')
        ->assertExitCode(0);

    $mlx = File::get($this->dir.'/billing-mlx.yaml');

    expect($mlx)->toContain('learning_rate: 2.0e-4')
        ->and($mlx)->toContain('iters: 130')
        ->and($mlx)->toContain('rank: 16')
        ->and($mlx)->toContain('# loss on the final assistant message only')
        ->and(File::get($this->dir.'/billing-peft.yaml'))->toContain('learning_rate: 2.0e-4')->toContain('num_train_epochs: 2');
});

it('writes nothing and fails when no run passes the checks', function () {
    $this->artisan('eval:sweep-report', ['experiment' => 'mlflow:billing-sweep', '--manifest' => $this->manifest, '--base-injection' => '0.01', '--write-best' => $this->dir])
        ->expectsOutputToContain('No run passed the checks: nothing to write.')
        ->assertExitCode(1);
});

it('says what is wrong with the experiment or the manifest', function () {
    $this->artisan('eval:sweep-report', ['experiment' => 'billing-sweep'])->expectsOutputToContain('The experiment is mlflow:<name>.')->assertExitCode(1);

    $this->artisan('eval:sweep-report', ['experiment' => 'mlflow:billing-sweep', '--manifest' => '/nope/sweep.json'])->expectsOutputToContain('No sweep manifest at /nope/sweep.json')->assertExitCode(1);
});

it('edits the configs line by line, keeps their comments, and says when one changed shape', function () {
    File::put($this->dir.'/billing-peft.yaml', "lora:\n  r: 16\ntrainer:\n  learning_rate: 1.0e-4\n");

    File::put($this->dir.'/billing-mlx.yaml', "learning_rate: 1e-4\niters: 5\nlora_parameters:\n  rank: 16   # keep\n");

    expect(fn () => (new BestSettings)->write($this->dir, ['learning_rate' => 0.0002, 'lora_r' => 8, 'num_epochs' => 3], 100))
        ->toThrow(InvalidArgumentException::class, 'has no line matching');
});

it('formats small learning rates so the Python side reads them as numbers', function () {
    File::copy(base_path('training/configs/billing-peft.yaml'), $this->dir.'/billing-peft.yaml');

    File::copy(base_path('training/configs/billing-mlx.yaml'), $this->dir.'/billing-mlx.yaml');

    (new BestSettings)->write($this->dir, ['learning_rate' => 0.00004, 'lora_r' => 32, 'num_epochs' => 1], 101, 2);

    expect(File::get($this->dir.'/billing-peft.yaml'))->toContain('learning_rate: 4.0e-5')
        ->and(File::get($this->dir.'/billing-mlx.yaml'))->toContain('iters: 51')->toContain('rank: 32');
});
