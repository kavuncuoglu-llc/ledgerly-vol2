<?php

use App\Ai\AiManager;
use App\Ai\Cost\PriceMap;
use App\Ai\FineTuning\FakeFineTuningClient;
use App\Ai\FineTuning\FineTuneSpec;
use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Prompts\PromptBindingViolated;
use App\Ai\Prompts\SupportPrompt;
use App\Evals\GoldenSet;
use App\Http\Controllers\FakeFineTunedChatController;
use App\Models\FineTuneJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\Support\FineTuningWorld;
use Tests\Support\TinyDataset;

// Chapter 41, Topics 5 and 8: the offline driver, registering a model as a role, and the prompt version bound to the role.
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ft-fake-'.bin2hex(random_bytes(4));

    $this->out = $this->dir.'/billing-hosted.jsonl';

    $this->env = $this->dir.'/.env';

    File::ensureDirectoryExists($this->dir);

    FineTuningWorld::dataset();

    config(['ai.fine_tuning.drivers.frontier' => 'fake', 'ai.fine_tuning.fake' => ['directory' => $this->dir.'/fake', 'seconds' => 0], 'services.mlflow.tracking_uri' => 'http://mlflow.test']);

    Http::fake(['mlflow.test/*' => Http::response(['run' => ['info' => ['run_id' => 'r']], 'experiment' => ['experiment_id' => '1']])]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    TinyDataset::cleanUp();
});

function ftFakeRun(): FineTuneJob
{
    Artisan::call('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => test()->out]);

    Artisan::call('ai:ft:submit', ['dataset' => 'billing-v1', '--base' => 'fake-base-8b', '--epochs' => 2]);

    Artisan::call('ai:ft:poll');

    return FineTuneJob::query()->latest('id')->first();
}

it('runs every line of the Laravel side offline: prepare, submit, poll, and a model id', function () {
    $job = ftFakeRun();

    expect($job->status)->toBe('succeeded')
        ->and($job->model_id)->toStartWith('ft:fake-billing:')
        ->and($job->trained_tokens)->toBeGreaterThan(0)
        ->and($job->events()->count())->toBeGreaterThan(0);

    $client = new FakeFineTuningClient($this->dir.'/fake', 0);

    expect($client->fileDeleted($job->provider_file_id))->toBeTrue();
});

it('goes through queued and running before it succeeds when the job takes a while', function () {
    $client = new FakeFineTuningClient($this->dir.'/fake', 30);

    file_put_contents($this->out, "{}\n{}\n");

    $file = $client->uploadTrainingFile($this->out);

    $job = $client->createJob(new FineTuneSpec('fake-base-8b', epochs: 2), $file, null);

    expect($client->status($job)->state)->toBe('queued');

    $state = json_decode(file_get_contents($this->dir."/fake/{$job}.json"), true);

    $state['started'] -= 15;

    file_put_contents($this->dir."/fake/{$job}.json", json_encode($state));

    expect($client->status($job)->state)->toBe('running')
        ->and($client->events($job))->not->toBeEmpty();

    $client->cancel($job);

    expect($client->status($job)->state)->toBe('cancelled');
});

it('registers the model as the role: the variables written, the price left empty on purpose', function () {
    $job = ftFakeRun();

    $this->artisan('ai:ft:register', ['job' => $job->id, '--env' => $this->env])
        ->expectsOutputToContain("role frontier-billing-ft → {$job->model_id}")
        ->expectsOutputToContain('no price yet: BILLING_FT_RATE_IN, BILLING_FT_RATE_OUT, BILLING_FT_TRAIN_RATE are empty')
        ->assertExitCode(0);

    $env = file_get_contents($this->env);

    expect($env)->toContain("BILLING_FT_MODEL={$job->model_id}")
        ->and($env)->toContain('BILLING_FT_BASE=fake-base-8b')
        ->and($env)->toContain("BILLING_FT_JOB={$job->provider_job_id}")
        ->and($env)->toContain("BILLING_FT_DATASET_HASH={$job->dataset_hash}")
        ->and($env)->toMatch('/^BILLING_FT_RATE_IN=$/m')
        ->and($job->fresh()->role)->toBe('frontier-billing-ft');

    $this->artisan('ai:ft:register', ['job' => $job->id, '--env' => $this->env])->assertExitCode(0);

    expect(substr_count(file_get_contents($this->env), 'BILLING_FT_MODEL='))->toBe(1);
});

it('prints the variables instead of writing them, and refuses a job that has no model or a role that is not fine-tuned', function () {
    $job = ftFakeRun();

    $this->artisan('ai:ft:register', ['job' => $job->id, '--print' => true])->expectsOutputToContain("BILLING_FT_MODEL={$job->model_id}")->assertExitCode(0);

    $this->artisan('ai:ft:register', ['job' => $job->id, '--role' => 'local'])->expectsOutputToContain('[local] is not a fine-tuned role in config/ai.php. Roles with a fine_tuned block: frontier-billing-ft')->assertExitCode(1);

    $running = FineTuneJob::query()->create(['provider' => 'frontier', 'driver' => 'fake', 'dataset' => 'billing-v1', 'dataset_hash' => 'sha256:x', 'sources' => [], 'status' => 'running']);

    $this->artisan('ai:ft:register', ['job' => $running->id])->expectsOutputToContain('is running: only a succeeded job has a model to register')->assertExitCode(1);
});

it('prices the fine-tune once its rates are in the environment, and not before', function () {
    config(['ai-pricing.models' => []]);

    expect(app(PriceMap::class)->find('frontier-billing-ft', 'ft:fake-billing:abc'))->toBeNull();

    config(['ai-pricing.models' => ['ft:fake-billing:abc' => ['input' => 6.0, 'output' => 24.0, 'per' => 1_000_000, 'source' => 'https://example.com/pricing']]]);

    expect(app(PriceMap::class)->find('frontier-billing-ft', 'ft:fake-billing:abc'))->toMatchArray(['input' => 6.0, 'output' => 24.0]);
});

it('has a frontier-billing-ft role that is pinned, carries its provenance and is not set up until it has a model', function () {
    $role = config('ai.providers.frontier-billing-ft');

    expect($role['pinned'])->toBeTrue()
        ->and($role['fine_tuned'])->toHaveKeys(['base', 'dataset', 'dataset_hash', 'job', 'prompt_version'])
        ->and($role['fine_tuned']['prompt_version'])->toBe(SupportPrompt::FINE_TUNED_VERSION)
        ->and($role['privacy']['redact'])->toBeTrue()
        ->and(app(AiManager::class)->configured('frontier-billing-ft'))->toBeFalse()
        ->and(config('ai.features')['support.reply']['allowed_providers'])->toContain('frontier-billing-ft');
});

describe('the prompt version bound to the role', function () {
    beforeEach(function () {
        config(['ai.security.canary' => '']);

        $this->long = SupportPrompt::base()."\n\n- role: bookkeeper";

        $this->messages = [['role' => 'system', 'content' => $this->long], ['role' => 'user', 'content' => 'q']];
    });

    it('sends the fine-tuned role the short system message, keeping what is added after it', function () {
        $bound = SupportPrompt::boundTo($this->messages, 'frontier-billing-ft');

        expect($bound[0]['content'])->toStartWith(FineTunedSupportPrompt::system())
            ->and($bound[0]['content'])->toEndWith('- role: bookkeeper')
            ->and($bound[0]['content'])->not->toContain('At most four sentences')
            ->and($bound[1])->toBe($this->messages[1]);
    });

    it('sends every other role the long message untouched', function () {
        expect(SupportPrompt::boundTo($this->messages, 'local'))->toBe($this->messages)
            ->and(SupportPrompt::boundTo($this->messages, 'frontier'))->toBe($this->messages);
    });

    it('refuses the short message on a role that was not trained on it, and anything else on the fine-tune', function () {
        $short = [['role' => 'system', 'content' => FineTunedSupportPrompt::system()]];

        expect(fn () => SupportPrompt::boundTo($short, 'local'))->toThrow(PromptBindingViolated::class, 'is not a fine-tuned role');

        expect(fn () => SupportPrompt::boundTo([['role' => 'system', 'content' => 'something else']], 'frontier-billing-ft'))->toThrow(PromptBindingViolated::class, 'neither the long one to swap nor the short one');

        expect(SupportPrompt::boundTo($short, 'frontier-billing-ft'))->toBe($short);
    });

    it('says which prompt version a role answers with', function () {
        expect(SupportPrompt::versionFor('frontier-billing-ft'))->toBe(SupportPrompt::FINE_TUNED_VERSION)
            ->and(SupportPrompt::versionFor('local'))->toBe(SupportPrompt::VERSION)
            ->and(SupportPrompt::versionFor('local', 'experiment-7'))->toBe('experiment-7');
    });
});

describe('the fake fine-tune\'s chat replies', function () {
    beforeEach(function () {
        $golden = sys_get_temp_dir().'/ft-golden-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($golden);

        File::put($golden.'/cases.jsonl', json_encode(['id' => 'b1', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'Why is there a fee on INV-4471?', 'expected_behavior' => 'answer', 'reference_answer' => 'The fee is 1.5% a month. It is applied after 30 days. Pay INV-4471 to stop it.'])."\n");

        $this->golden = new GoldenSet($golden);
    });

    function fakeChat(string $model, bool $stream = false)
    {
        $request = Request::create('/fake-ft/v1/chat/completions', 'POST', ['model' => $model, 'stream' => $stream, 'messages' => [['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => "Question:\n<untrusted-ab>Why is there a fee on INV-4471?</untrusted-ab>"]]]);

        return app(FakeFineTunedChatController::class)($request, test()->golden);
    }

    it('answers the fake model with the whole reference answer and the base without its last sentence', function () {
        $tuned = fakeChat('ft:fake-billing:abc')->getData(true);

        $base = fakeChat('fake-base-8b')->getData(true);

        expect($tuned['choices'][0]['message']['content'])->toContain('Pay INV-4471 to stop it.')
            ->and($base['choices'][0]['message']['content'])->not->toContain('Pay INV-4471')
            ->and($tuned['usage']['prompt_tokens'])->toBeGreaterThan(0);
    });

    it('streams the same reply as server-sent events', function () {
        ob_start();

        fakeChat('ft:fake-billing:abc', stream: true)->sendContent();

        $body = ob_get_clean();

        expect($body)->toContain('Pay INV-4471 to stop it.')->and($body)->toEndWith("data: [DONE]\n\n");
    });
});
