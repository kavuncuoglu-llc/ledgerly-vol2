<?php

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Registry\TemplateGate;
use App\Models\ModelRegistryEntry;
use Illuminate\Support\Facades\File;
use Tests\Support\FineTuningWorld;
use Tests\Support\TinyDataset;

// Chapter 42, Build: `registry:add` records an exported fine-tune as a candidate, with provenance read from the dataset and the export record.
class FakeGate extends TemplateGate
{
    public array $asked = [];

    public function __construct(private bool $passes = true) {}

    public function check(string $model, ?string $tokenizer = null, string $server = 'ollama', ?string $rows = null): array
    {
        $this->asked[] = [$model, $tokenizer, $server, $rows];

        return ['passed' => $this->passes, 'output' => $this->passes ? 'ok' : 'training: 538 tokens, ollama: 557 tokens'];
    }
}

beforeEach(function () {
    $this->dataset = FineTuningWorld::dataset();

    $this->dir = sys_get_temp_dir().'/registry-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    $this->export = $this->dir.'/export.json';

    File::put($this->export, json_encode(['name' => 'ledgerly-billing-ft', 'base' => 'meta-llama/Llama-3.1-8B-Instruct', 'backend' => 'mlx', 'quantization' => 'Q4_K_M', 'artifacts' => ['adapter' => 'sha256:aa', 'gguf' => 'sha256:bb']]));

    $this->gate = new FakeGate;

    app()->instance(TemplateGate::class, $this->gate);
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::deleteDirectory(storage_path('eval'));

    TinyDataset::cleanUp();
});

it('adds a candidate with its provenance read from the dataset and the export record', function () {
    $this->artisan('registry:add', ['model' => 'ledgerly-billing-ft', '--dataset' => 'billing-v1', '--run' => 'mlflow:billing-mlx', '--status' => 'candidate', '--export' => $this->export])
        ->expectsOutputToContain('billing v1 · ledgerly-billing-ft · candidate · dataset billing-v1 sha256:')
        ->assertExitCode(0);

    $entry = ModelRegistryEntry::query()->first();

    expect($entry->stage)->toBe('candidate')
        ->and($entry->version)->toBe(1)
        ->and($entry->base)->toBe('meta-llama/Llama-3.1-8B-Instruct')
        ->and($entry->dataset_hash)->toBe($this->dataset->hash('train.jsonl'))
        ->and($entry->training_run)->toBe('mlflow:billing-mlx')
        ->and($entry->prompt_version)->toBe(SupportPrompt::FINE_TUNED_VERSION)
        ->and($entry->artifacts)->toMatchArray(['adapter' => 'sha256:aa', 'gguf' => 'sha256:bb', 'quantization' => 'Q4_K_M', 'backend' => 'mlx'])
        ->and($entry->artifacts['dataset_manifest'])->toStartWith('sha256:')
        ->and($entry->history[0])->toMatchArray(['stage' => 'candidate', 'reason' => 'exported and passed the template check']);

    expect($this->gate->asked)->toBe([['ledgerly-billing-ft', null, 'ollama', null]]);
});

it('numbers the versions of a name and keeps names apart', function () {
    foreach (['billing', 'billing', 'support'] as $i => $name) {
        $this->artisan('registry:add', ['model' => "m{$i}", '--dataset' => 'billing-v1', '--name' => $name, '--base' => 'b'])->assertExitCode(0);
    }

    expect(ModelRegistryEntry::query()->where('name', 'billing')->pluck('version')->all())->toBe([1, 2])
        ->and(ModelRegistryEntry::query()->where('name', 'support')->value('version'))->toBe(1);
});

it('refuses a model that fails its template check, and says why', function () {
    app()->instance(TemplateGate::class, new FakeGate(passes: false));

    $this->artisan('registry:add', ['model' => 'ledgerly-billing-ft', '--dataset' => 'billing-v1', '--base' => 'b'])
        ->expectsOutputToContain("failed its template check, so it is not a candidate:\ntraining: 538 tokens, ollama: 557 tokens")
        ->assertExitCode(1);

    expect(ModelRegistryEntry::query()->count())->toBe(0);
});

it('can skip the check, and the history says so', function () {
    $this->artisan('registry:add', ['model' => 'm', '--dataset' => 'billing-v1', '--base' => 'b', '--no-check' => true])->assertExitCode(0);

    expect($this->gate->asked)->toBe([])->and(ModelRegistryEntry::query()->first()->history[0]['reason'])->toBe('added without the template check');
});

it('keeps the comparison results behind the entry', function () {
    File::ensureDirectoryExists(storage_path('eval'));

    File::put(storage_path('eval/last-compare.json'), json_encode(['set' => 'golden:billing', 'cases' => 61, 'ran_on' => '2026-11-12', 'results' => [['role' => 'local-billing-ft', 'metrics' => ['golden_pass_rate' => 0.97]]]]));

    $this->artisan('registry:add', ['model' => 'm', '--dataset' => 'billing-v1', '--base' => 'b', '--eval' => 'eval:last'])->assertExitCode(0);

    expect(ModelRegistryEntry::query()->first()->evaluations[0])->toMatchArray(['set' => 'golden:billing', 'cases' => 61])
        ->and(ModelRegistryEntry::query()->first()->evaluations[0]['roles'][0]['metrics']['golden_pass_rate'])->toEqual(0.97);
});

it('refuses what it cannot record: a later stage, no dataset, a dataset that is not there, a missing export', function () {
    $this->artisan('registry:add', ['model' => 'm', '--dataset' => 'billing-v1', '--status' => 'production'])->expectsOutputToContain('A new version is a candidate')->assertExitCode(1);

    $this->artisan('registry:add', ['model' => 'm'])->expectsOutputToContain('Name the dataset')->assertExitCode(1);

    $this->artisan('registry:add', ['model' => 'm', '--dataset' => 'billing-v9'])->expectsOutputToContain('No dataset billing-v9')->assertExitCode(1);

    $this->artisan('registry:add', ['model' => 'm', '--dataset' => 'billing-v1', '--export' => '/nope/export.json'])->expectsOutputToContain('No export record at /nope/export.json')->assertExitCode(1);

    expect(ModelRegistryEntry::query()->count())->toBe(0);
});
