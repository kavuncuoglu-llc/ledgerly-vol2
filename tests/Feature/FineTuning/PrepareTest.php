<?php

use App\Ai\Training\Dataset;
use App\Models\FineTuneJob;
use Illuminate\Support\Facades\File;
use Tests\Support\FineTuningWorld;
use Tests\Support\TinyDataset;

// Chapter 41, Topic 2: what may leave, the provider's format, and every row validated before anything is uploaded.
beforeEach(function () {
    $this->out = sys_get_temp_dir().'/ft-prepare-'.bin2hex(random_bytes(4)).'/billing-hosted.jsonl';

    FineTuningWorld::dataset();
});

afterEach(function () {
    File::deleteDirectory(dirname($this->out));

    TinyDataset::cleanUp();
});

it('writes only the parts without customer data, as chat messages, and says what the consent rules left out', function () {
    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--validation' => true])
        ->expectsOutputToContain('rows 5 · invalid 0')
        ->expectsOutputToContain('excluded by consent rules: edits 1 · production 2')
        ->assertExitCode(0);

    $lines = array_map(fn ($line) => json_decode($line, true), file($this->out, FILE_IGNORE_NEW_LINES));

    expect($lines)->toHaveCount(5)
        ->and($lines[0]['messages'])->toHaveCount(3)
        ->and(collect($lines)->pluck('messages')->flatten(1)->where('role', 'assistant')->pluck('content')->every(fn ($c) => str_contains($c, 'late fee')))->toBeTrue();

    $validation = preg_replace('/\.jsonl$/', '', $this->out).'.validation.jsonl';

    expect(file($validation))->toHaveCount(1);

    $job = FineTuneJob::query()->first();

    expect($job->status)->toBe('prepared')
        ->and($job->dataset_hash)->toBe(Dataset::named('billing-v1')->hash('train.jsonl'))
        ->and($job->sources)->toBe(['exemplars', 'synthetic', 'replay'])
        ->and($job->tokens_per_epoch)->toBeGreaterThan(0);
});

it('refuses customer-derived parts the manifest does not allow', function () {
    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--only' => 'exemplars,production'])
        ->expectsOutputToContain('Refused: production hold customer data')
        ->assertExitCode(1);

    expect(file_exists($this->out))->toBeFalse()->and(FineTuneJob::query()->count())->toBe(0);
});

it('needs the recorded allowance and the provider\'s own answers before customer-derived rows go', function () {
    $this->artisan('dataset:allow', ['dataset' => 'billing-v1', '--provider' => 'frontier', '--reason' => 'DPA signed; terms section 4'])->assertExitCode(0);

    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--only' => 'exemplars,production'])
        ->expectsOutputToContain('no signed data processing agreement')
        ->assertExitCode(1);

    config(['ai.providers.frontier.privacy.dpa_signed' => true]);

    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--only' => 'exemplars,production'])
        ->expectsOutputToContain('rows 4 · invalid 0')
        ->assertExitCode(0);

    expect(Dataset::named('billing-v1')->readJson('manifest.json')['hosts'][0])->toMatchArray(['provider' => 'frontier', 'reason' => 'DPA signed; terms section 4']);
});

it('stops on an invalid row and names it, writing nothing', function () {
    $dataset = Dataset::named('billing-v1');

    $rows = $dataset->read('train.jsonl');

    $rows[0]['completion'] = [['role' => 'assistant', 'content' => '']];

    $rows[2]['completion'] = [['role' => 'user', 'content' => 'I end on a user turn']];

    $dataset->write('train.jsonl', $rows);

    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out])
        ->expectsOutputToContain('row 1 (train-0): message 2 (assistant) is empty')
        ->expectsOutputToContain('row 3 (train-2): does not end with an assistant turn')
        ->expectsOutputToContain('2 invalid: nothing was written.')
        ->assertExitCode(1);

    expect(file_exists($this->out))->toBeFalse()->and(FineTuneJob::query()->count())->toBe(0);
});

it('checks tool turns: arguments are a JSON string and a tool message follows a call', function () {
    $dataset = Dataset::named('billing-v1');

    $rows = $dataset->read('train.jsonl');

    $rows[0]['prompt'][] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => ['number' => 'INV-1']]]]];

    $rows[0]['prompt'][] = ['role' => 'tool', 'content' => '{"total":10}'];

    $dataset->write('train.jsonl', $rows);

    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out])
        ->expectsOutputToContain('tool call arguments must be a JSON string')
        ->expectsOutputToContain('has no tool_call_id')
        ->assertExitCode(1);
});

it('prices the training when the base model has a rate, and points at Appendix F when it does not', function () {
    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out])
        ->expectsOutputToContain('estimated training cost: see Appendix F for the current rate')
        ->assertExitCode(0);

    config(['ai-pricing.training' => ['base-x' => ['per_million' => 25.0, 'source' => 'https://example.com/pricing']]]);

    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--base' => 'base-x'])
        ->expectsOutputToContain('at $25 per million trained tokens (https://example.com/pricing)')
        ->assertExitCode(0);
});

it('refuses a provider with no fine-tuning driver', function () {
    $this->artisan('ai:ft:prepare', ['dataset' => 'billing-v1', '--out' => $this->out, '--provider' => 'local'])
        ->expectsOutputToContain('No fine-tuning driver for [local]')
        ->assertExitCode(1);
});
