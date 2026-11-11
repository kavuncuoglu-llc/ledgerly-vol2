<?php

use App\Ai\Training\Dataset;
use App\Ai\Training\DatasetFootprint;
use App\Ai\Training\HubUploader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TinyDataset;

// Chapter 40, Topic 8 and the Build: publish, show and forget.
class RecordingHub implements HubUploader
{
    public array $uploads = [];

    public function upload(string $repository, array $files): string
    {
        $this->uploads[] = [$repository, array_map(fn ($path) => file_get_contents($path), $files)];

        return 'a41c09e';
    }
}

function trFinishedDataset(string $name = 'billing-v1'): Dataset
{
    $dataset = Dataset::named($name);

    $records = [];

    foreach (range(1, 6) as $i) {
        $records[] = ['conversation' => $i, 'question' => "Q{$i}", 'source' => $i <= 4 ? 'production' : 'edits', 'tenant' => 't'.$i, 'user' => 'u'.$i];
    }

    $exported = TinyDataset::exported($name, $records);

    $train = $exported->joined();

    $dataset->write('train.jsonl', array_map(fn ($r) => ['prompt' => $r['prompt'], 'completion' => $r['completion']], array_slice($train, 0, 4)));
    $dataset->write('validation.jsonl', array_map(fn ($r) => ['prompt' => $r['prompt'], 'completion' => $r['completion']], array_slice($train, 4)));
    $dataset->write('train.meta.jsonl', array_map(fn ($r) => $r['meta'], array_slice($train, 0, 4)));
    $dataset->write('validation.meta.jsonl', array_map(fn ($r) => $r['meta'], array_slice($train, 4)));

    $synthetic = ['prompt' => [['role' => 'user', 'content' => 'synthetic q']], 'completion' => [['role' => 'assistant', 'content' => 'synthetic a']]];

    $dataset->write('train.jsonl', [...$dataset->read('train.jsonl'), $synthetic]);
    $dataset->write('train.meta.jsonl', [...$dataset->read('train.meta.jsonl'), ['id' => 'syn1', 'source' => 'synthetic', 'category' => 'billing', 'subcategory' => 'synthetic', 'conversation' => 'syn-conversation', 'tenant' => '', 'user' => '', 'consent' => 'n/a']]);

    $dataset->writeJson('export.json', ['category' => 'billing', 'region' => 'eu', 'patterns_only' => true, 'system_version' => '2026-11-11.ft1', 'examples' => 8]);
    $dataset->writeJson('review.json', ['examples' => 8, 'accepted' => 6, 'edited' => 1, 'rejected' => 1, 'rejection_reasons' => ['promise not allowed' => 1], 'double_reviewed' => 2, 'agreement' => 0.5]);
    $dataset->writeJson('clean_report.json', ['input' => 7, 'filters' => ['uncited' => 1], 'near_duplicates' => 0, 'decontaminated' => [], 'balance' => [], 'output' => 6, 'tokenizer' => 'Qwen/Qwen2.5-0.5B-Instruct', 'thresholds' => ['max_tokens' => 2048]]);
    $dataset->writeJson('split_report.json', ['examples' => 7, 'train' => 5, 'validation' => 2, 'composition' => ['production' => 4, 'edits' => 2, 'synthetic' => 1], 'seed' => 42, 'split' => 'by conversation, stratified by subcategory']);

    return $dataset;
}

beforeEach(function () {
    TinyDataset::use();

    Storage::fake('training-eu');

    $this->hub = new RecordingHub;

    app()->instance(HubUploader::class, $this->hub);
});

afterEach(fn () => TinyDataset::cleanUp());

it('refuses a dataset that is not finished', function () {
    TinyDataset::exported('billing-p0', [['conversation' => 1, 'question' => 'Q']]);

    $this->artisan('dataset:publish', ['dataset' => 'billing-p0'])->expectsOutputToContain('is not finished')->assertExitCode(1);
});

it('writes the manifest with the funnel, removals, composition, consent and a hash per file', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    $manifest = Dataset::named('billing-v1')->readJson('manifest.json');

    expect($manifest['funnel'])->toMatchArray(['exported' => 8, 'reviewed' => 7, 'accepted' => 6, 'edited' => 1, 'rejected' => 1, 'filters' => 1, 'task_examples' => 6])
        ->and($manifest['removals']['review'])->toBe(['promise not allowed' => 1])
        ->and($manifest['composition'])->toBe(['production' => 4, 'edits' => 2, 'synthetic' => 1])
        ->and($manifest['consent'])->toMatchArray(['production' => 'tenant opt-in under terms v4', 'edits' => 'n/a', 'synthetic' => 'n/a'])
        ->and($manifest['tokenizer'])->toBe('Qwen/Qwen2.5-0.5B-Instruct')
        ->and($manifest['review'])->toBe(['double_reviewed' => 2, 'agreement' => 0.5])
        ->and($manifest['files'])->toHaveKeys(['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl'])
        ->and($manifest['files']['train.jsonl'])->toBe(Dataset::named('billing-v1')->hash('train.jsonl'))
        ->and($manifest['stored'])->toMatchArray(['region' => 'eu', 'disk' => 'training-eu']);
});

it('stores the customer-derived files in the region\'s disk, with the manifest and the card', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    foreach (['train.jsonl', 'validation.jsonl', 'train.meta.jsonl', 'validation.meta.jsonl', 'manifest.json', 'README.md'] as $file) {
        Storage::disk('training-eu')->assertExists("billing-v1/{$file}");
    }

    expect(Storage::disk('training-eu')->get('billing-v1/README.md'))->toContain('production: 4 examples')->toContain('Known gaps');
});

it('refuses a region other than the one the data was exported in, and a region with no disk', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'us'])->expectsOutputToContain('exported in region [eu]')->assertExitCode(1);

    $dataset = Dataset::named('billing-v1');

    $dataset->writeJson('export.json', ['region' => 'ap'] + $dataset->readJson('export.json'));

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'ap'])->expectsOutputToContain('No storage disk')->assertExitCode(1);
});

it('sends only the files with no customer data to the Hub, never an example from a conversation', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu', '--hub-private' => 'ledgerly/billing-synthetic'])->assertExitCode(0);

    expect($this->hub->uploads)->toHaveCount(1)
        ->and($this->hub->uploads[0][0])->toBe('ledgerly/billing-synthetic')
        ->and(array_keys($this->hub->uploads[0][1]))->toBe(['synthetic.jsonl'])
        ->and($this->hub->uploads[0][1]['synthetic.jsonl'])->toContain('synthetic q')->not->toContain('Q1')
        ->and(Dataset::named('billing-v1')->readJson('manifest.json')['hub'])->toMatchArray(['repository' => 'ledgerly/billing-synthetic', 'revision' => 'a41c09e', 'private' => true]);

    foreach (File::files(Dataset::named('billing-v1')->path('hub')) as $file) {
        expect($file->getContents())->not->toContain('Q1')->not->toContain('INV-1000');
    }
});

it('uploads nothing to the Hub unless asked', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    expect($this->hub->uploads)->toBe([])
        ->and(Dataset::named('billing-v1')->readJson('manifest.json')['hub'])->toBeNull();
});

it('prints the funnel, composition, consent and hashes the training configs pin', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    $this->artisan('dataset:show', ['dataset' => 'billing-v1'])
        ->expectsOutputToContain('exported 8 → reviewed 7 (accepted 6, edited 1; rejected 1)')
        ->expectsOutputToContain('production 57% · edits 29% · synthetic 14%')
        ->expectsOutputToContain('train 5 · validation 2 (by conversation, stratified by subcategory)')
        ->expectsOutputToContain('tenant opt-in under terms v4 (production)')
        ->expectsOutputToContain('double-reviewed 2 · agreement 50%')
        ->expectsOutputToContain('training-eu:billing-v1/')
        ->expectsOutputToContain('train.jsonl sha256:')
        ->assertExitCode(0);
});

it('says to publish first when there is no manifest', function () {
    trFinishedDataset();

    $this->artisan('dataset:show', ['dataset' => 'billing-v1'])->expectsOutputToContain('run dataset:publish')->assertExitCode(1);
});

it('forgets a conversation: a new version without its examples, the old one scheduled for retirement', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    $this->artisan('dataset:forget', ['dataset' => 'billing-v1', '--conversation' => ['2'], '--reason' => 'erasure request 41'])
        ->expectsOutputToContain('billing-v2: 6 examples, 1 removed')
        ->assertExitCode(0);

    $new = Dataset::named('billing-v2');

    $old = Dataset::named('billing-v1')->readJson('manifest.json');

    expect(collect($new->records('final'))->pluck('meta.conversation')->all())->not->toContain(TinyDataset::hash(2))
        ->and($new->readJson('manifest.json')['derived_from'])->toMatchArray(['dataset' => 'billing-v1', 'forgotten_examples' => 1, 'reason' => 'erasure request 41'])
        ->and($new->readJson('manifest.json')['counts']['total'])->toBe(6)
        ->and($new->readJson('manifest.json')['stored']['path'])->toBe('billing-v2/')
        ->and($new->readJson('manifest.json')['files']['train.jsonl'])->toBe($new->hash('train.jsonl'))
        ->and($old['retirement'])->toMatchArray(['status' => 'scheduled', 'replaced_by' => 'billing-v2', 'reason' => 'erasure request 41'])
        ->and($old['retirement']['retire_after'])->toBe(now()->addDays((int) config('ai.training.retire_after_days'))->toDateString());

    Storage::disk('training-eu')->assertExists('billing-v2/manifest.json');

    $this->artisan('dataset:show', ['dataset' => 'billing-v1'])->expectsOutputToContain('retirement    scheduled: replaced by billing-v2')->assertExitCode(0);
});

it('never overwrites a version, and finds nothing to forget in a conversation that is not there', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    $this->artisan('dataset:forget', ['dataset' => 'billing-v1', '--conversation' => ['99']])->expectsOutputToContain('nothing to forget')->assertExitCode(1);

    $this->artisan('dataset:forget', ['dataset' => 'billing-v1', '--conversation' => ['1']])->assertExitCode(0);

    $this->artisan('dataset:forget', ['dataset' => 'billing-v1', '--conversation' => ['3']])->expectsOutputToContain('already exists')->assertExitCode(1);
});

it('forgets by the hash itself, and refuses an id with no key to hash it', function () {
    trFinishedDataset();

    $this->artisan('dataset:publish', ['dataset' => 'billing-v1', '--region' => 'eu'])->assertExitCode(0);

    config(['ai.training.hash_key' => '']);

    $this->artisan('dataset:forget', ['dataset' => 'billing-v1', '--conversation' => ['3']])->expectsOutputToContain('Set AI_TRAINING_HASH_KEY')->assertExitCode(1);

    $this->artisan('dataset:forget', ['dataset' => 'billing-v1', '--conversation' => [TinyDataset::hash(3)], '--to' => 'billing-p9'])->assertExitCode(0);
});

it('wants a version name it can count from, or one given', function () {
    trFinishedDataset('billing-latest');

    $this->artisan('dataset:publish', ['dataset' => 'billing-latest', '--region' => 'eu'])->assertExitCode(0);

    $this->artisan('dataset:forget', ['dataset' => 'billing-latest', '--conversation' => ['1']])->expectsOutputToContain('does not end in -v<number>')->assertExitCode(1);
});

it('finds the dataset versions that hold a person\'s conversations, so user:forget can say which dataset:forget to run', function () {
    trFinishedDataset();

    $footprint = new DatasetFootprint;

    expect($footprint->of([2, 3]))->toBe(['billing-v1' => 2])
        ->and($footprint->of([99]))->toBe([])
        ->and($footprint->of([]))->toBe([]);

    config(['ai.training.hash_key' => '']);

    expect($footprint->of([2]))->toBe([]);
});
