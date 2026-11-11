<?php

use App\Ai\FineTuning\FineTuneEnded;
use App\Ai\FineTuning\FineTuneSucceeded;
use App\Models\FineTuneJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FineTuningWorld;
use Tests\Support\TinyDataset;

// Chapter 41, Topic 4: the OpenAI-shaped driver against faked HTTP, the states, the poller, the ledger row, MLflow and the deleted files.
beforeEach(function () {
    $this->out = sys_get_temp_dir().'/ft-hosted-'.bin2hex(random_bytes(4)).'/billing-hosted.jsonl';

    FineTuningWorld::dataset();

    config([
        'ai.providers.frontier.base_url' => 'https://api.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-not-real',
        'ai.fine_tuning.drivers.frontier' => 'openai',
        'services.mlflow.tracking_uri' => 'http://mlflow.test',
        'ai-pricing.training' => ['base-x' => ['per_million' => 25.0, 'source' => 'https://example.com/pricing']],
    ]);

    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--validation' => true])->assertExitCode(0);
});

afterEach(function () {
    File::deleteDirectory(dirname($this->out));

    TinyDataset::cleanUp();
});

function ftProvider(array $statuses, array $events = [], bool $mlflowDown = false): void
{
    $statuses = array_values($statuses);

    Http::fake([
        'api.example.com/v1/files' => Http::sequence()->push(['id' => 'file-train'])->push(['id' => 'file-valid'])->whenEmpty(Http::response(['id' => 'file-more'])),
        'api.example.com/v1/files/*' => Http::response(['deleted' => true]),
        'api.example.com/v1/fine_tuning/jobs' => Http::sequence()->push(['id' => 'ftjob-1', 'status' => 'validating_files'])->push(['id' => 'ftjob-2'])->push(['id' => 'ftjob-3']),
        'api.example.com/v1/fine_tuning/jobs/ftjob-1/events*' => Http::response(['data' => $events]),
        'api.example.com/v1/fine_tuning/jobs/ftjob-1/cancel' => Http::response(['id' => 'ftjob-1', 'status' => 'cancelled']),
        'api.example.com/v1/fine_tuning/jobs/ftjob-1' => Http::sequence($statuses === [] ? [Http::response(['status' => 'running'])] : array_map(fn ($s) => Http::response($s), $statuses)),
        'mlflow.test/*' => function (Request $request) use ($mlflowDown) {
            return match (true) {
                $mlflowDown => Http::response('down', 500),
                str_ends_with($request->url(), 'experiments/get-by-name') => Http::response(['experiment' => ['experiment_id' => '7']]),
                str_ends_with($request->url(), 'runs/create') => Http::response(['run' => ['info' => ['run_id' => 'run-abc']]]),
                default => Http::response([]),
            };
        },
    ]);
}

function ftSubmit(array $extra = []): int
{
    return Artisan::call('ai:ft:submit', ['dataset' => 'billing-v1', '--base' => 'base-x', '--epochs' => 3] + $extra);
}

it('uploads the file and the validation file, creates the job with the settings and records it', function () {
    ftProvider([]);

    expect(ftSubmit(['--seed' => 42, '--lr-multiplier' => 1.5]))->toBe(0);

    $job = FineTuneJob::query()->first();

    expect($job->status)->toBe('queued')
        ->and($job->provider_job_id)->toBe('ftjob-1')
        ->and($job->provider_file_id)->toBe('file-train')
        ->and($job->provider_validation_file_id)->toBe('file-valid')
        ->and($job->settings)->toBe(['epochs' => 3, 'learning_rate_multiplier' => 1.5, 'seed' => 42])
        ->and($job->base_model)->toBe('base-x');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/fine_tuning/jobs')) {
            return false;
        }

        $body = $request->data();

        return $body['model'] === 'base-x'
            && $body['training_file'] === 'file-train'
            && $body['validation_file'] === 'file-valid'
            && $body['method']['supervised']['hyperparameters'] === ['n_epochs' => 3, 'learning_rate_multiplier' => 1.5]
            && $body['seed'] === 42
            && $request->hasHeader('Authorization', 'Bearer sk-test-not-real');
    });
});

it('refuses to submit the same dataset hash, base and settings twice, and an override pays for it again', function () {
    ftProvider([]);

    expect(ftSubmit())->toBe(0);

    $this->artisan('ai:ft:submit', ['dataset' => 'billing-v1', '--base' => 'base-x', '--epochs' => 3])
        ->expectsOutputToContain('Job #'.FineTuneJob::query()->first()->id.' (queued) already trains this dataset hash, base and settings. Pass --again')
        ->assertExitCode(1);

    expect(FineTuneJob::query()->count())->toBe(1);

    $this->artisan('ai:ft:submit', ['dataset' => 'billing-v1', '--base' => 'base-x', '--epochs' => 3, '--again' => true, '--no-validation' => true])->assertExitCode(0);

    expect(FineTuneJob::query()->count())->toBe(2)->and(FineTuneJob::query()->latest('id')->first()->provider_job_id)->toBe('ftjob-2');
});

it('allows the same settings again once the earlier job failed', function () {
    ftProvider([['status' => 'failed', 'error' => ['message' => 'bad file']]]);

    ftSubmit();

    $this->artisan('ai:ft:poll')->assertExitCode(0);

    expect(FineTuneJob::query()->first()->status)->toBe('failed');

    expect(ftSubmit())->toBe(0);
});

it('needs a prepared file and a base model', function () {
    $this->artisan('ai:ft:submit', ['dataset' => 'billing-v2', '--base' => 'x'])->expectsOutputToContain('No prepared file for billing-v2')->assertExitCode(1);

    $this->artisan('ai:ft:submit', ['dataset' => 'billing-v1'])->expectsOutputToContain('Name the base model')->assertExitCode(1);
});

it('polls a running job: stores events, sends the loss to MLflow, and moves it to running', function () {
    ftProvider([['status' => 'running']], [
        ['id' => 'ftevent-2', 'created_at' => 1_800_000_020, 'level' => 'info', 'message' => 'Step 120/318: training loss=0.71', 'data' => ['step' => 120, 'train_loss' => 0.71, 'valid_loss' => 0.83]],
        ['id' => 'ftevent-1', 'created_at' => 1_800_000_010, 'level' => 'info', 'message' => 'Step 60/318: training loss=0.9', 'data' => ['step' => 60, 'train_loss' => 0.9]],
    ]);

    ftSubmit();

    $this->artisan('ai:ft:poll')->expectsOutputToContain('ftjob-1 running · step 120 · train loss 0.71 · validation loss 0.83  → MLflow run run-abc')->assertExitCode(0);

    $job = FineTuneJob::query()->first();

    expect($job->status)->toBe('running')
        ->and($job->last_event_id)->toBe('ftevent-2')
        ->and($job->mlflow_run_id)->toBe('run-abc')
        ->and($job->events()->count())->toBe(2)
        ->and($job->events()->where('mlflow_logged', false)->count())->toBe(0);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'runs/log-batch') && collect($r['metrics'] ?? [])->contains(fn ($m) => $m['key'] === 'valid_loss' && $m['value'] === 0.83 && $m['step'] === 120));
});

it('records success: the model id, a training row in the ledger, the files deleted at the provider, and an event', function () {
    Event::fake([FineTuneSucceeded::class]);

    ftProvider([['status' => 'succeeded', 'fine_tuned_model' => 'ft:base-x:ledgerly::abc', 'trained_tokens' => 3_810_000]]);

    ftSubmit();

    $this->artisan('ai:ft:poll')->expectsOutputToContain('succeeded · model ft:base-x:ledgerly::abc · trained tokens 3,810,000 · ledger: training row written · uploaded files deleted')->assertExitCode(0);

    $job = FineTuneJob::query()->first();

    expect($job->status)->toBe('succeeded')->and($job->model_id)->toBe('ft:base-x:ledgerly::abc')->and($job->finished_at)->not->toBeNull();

    $row = DB::table('ai_ledger')->where('kind', 'training')->first();

    expect($row->entry_id)->toBe('ft:'.$job->id)
        ->and($row->feature)->toBe('training.fine_tune')
        ->and($row->team)->toBe('platform')
        ->and($row->input_tokens)->toBe(3_810_000)
        ->and((int) $row->cost_micro_usd)->toBe(95_250_000)
        ->and($row->model)->toBe('base-x');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/file-train'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/file-valid'));

    Event::assertDispatched(FineTuneSucceeded::class);

    $this->artisan('ai:ft:poll')->expectsOutputToContain('No fine-tuning job is running.');

    expect(DB::table('ai_ledger')->where('kind', 'training')->count())->toBe(1);
});

it('writes the training row at zero and says why when the base model has no training rate', function () {
    config(['ai-pricing.training' => []]);

    ftProvider([['status' => 'succeeded', 'fine_tuned_model' => 'ft:x', 'trained_tokens' => 1000]]);

    ftSubmit();

    $this->artisan('ai:ft:poll')->assertExitCode(0);

    $row = DB::table('ai_ledger')->where('kind', 'training')->first();

    expect((int) $row->cost_micro_usd)->toBe(0)->and($row->reason)->toContain('unpriced training');
});

it('records a failure with the provider\'s reason, deletes the files and tells the team', function () {
    Event::fake([FineTuneEnded::class]);

    ftProvider([['status' => 'failed', 'error' => ['message' => 'The training file had too few examples.']]]);

    ftSubmit();

    $this->artisan('ai:ft:poll')->expectsOutputToContain('failed · The training file had too few examples. · uploaded files deleted')->assertExitCode(0);

    expect(FineTuneJob::query()->first())->status->toBe('failed')->failure_reason->toBe('The training file had too few examples.');

    Event::assertDispatched(FineTuneEnded::class);

    expect(DB::table('ai_ledger')->where('kind', 'training')->count())->toBe(0);
});

it('keeps going when one provider call fails, and tries again on the next poll', function () {
    Http::fake([
        'api.example.com/v1/files' => Http::sequence()->push(['id' => 'f1'])->push(['id' => 'f2']),
        'api.example.com/v1/fine_tuning/jobs' => Http::response(['id' => 'ftjob-1']),
        'api.example.com/v1/fine_tuning/jobs/ftjob-1' => Http::sequence()->push('overloaded', 503)->push(['status' => 'running']),
        'api.example.com/v1/fine_tuning/jobs/ftjob-1/events*' => Http::response(['data' => []]),
    ]);

    ftSubmit();

    $this->artisan('ai:ft:poll')->expectsOutputToContain('503')->assertExitCode(1);

    expect(FineTuneJob::query()->first()->status)->toBe('queued');

    $this->artisan('ai:ft:poll')->assertExitCode(0);

    expect(FineTuneJob::query()->first()->status)->toBe('running');
});

it('does not fail the poll when MLflow is down: the events wait for the next one', function () {
    ftProvider([['status' => 'running']], [['id' => 'e1', 'created_at' => 1, 'message' => 's', 'data' => ['step' => 1, 'train_loss' => 1.0]]], mlflowDown: true);

    ftSubmit();

    $this->artisan('ai:ft:poll')->assertExitCode(0);

    expect(FineTuneJob::query()->first()->events()->where('mlflow_logged', false)->count())->toBe(1);
});

it('cancels a job at the provider, deletes its files and notes who cancelled it', function () {
    ftProvider([]);

    ftSubmit();

    $this->artisan('ai:ft:cancel', ['job' => FineTuneJob::query()->first()->id])->expectsOutputToContain('cancelled · uploaded files deleted at the provider')->assertExitCode(0);

    expect(FineTuneJob::query()->first())->status->toBe('cancelled');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/fine_tuning/jobs/ftjob-1/cancel'));

    $this->artisan('ai:ft:cancel', ['job' => FineTuneJob::query()->first()->id])->expectsOutputToContain('is cancelled')->assertExitCode(1);
});

it('does not let a late poll bring a finished job back to life', function () {
    $job = FineTuneJob::query()->first();

    $job->transitionTo('succeeded');

    $job->transitionTo('running');

    expect($job->fresh()->status)->toBe('succeeded');

    expect(fn () => $job->transitionTo('paused'))->toThrow(InvalidArgumentException::class);
});

it('is scheduled every five minutes', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'ai:ft:poll'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/5 * * * *');
});
