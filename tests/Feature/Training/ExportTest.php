<?php

use App\Ai\Privacy\Ner;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeNer;
use Tests\Support\TrainingCorpus;

// Chapter 40, Topic 4: the export rebuilds each turn the way it is served, redacts with surrogates, and keeps metadata out of the rows.
beforeEach(function () {
    $this->seed();

    $this->seeder = TrainingCorpus::build(5);

    $this->out = sys_get_temp_dir().'/export-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    TrainingCorpus::cleanUp();

    File::deleteDirectory($this->out);
});

function trExportCorpus(string $out, array $options = []): void
{
    test()->artisan('dataset:export', ['category' => 'billing', '--out' => $out, '--patterns-only' => true] + $options)->assertExitCode(0);
}

function trExportedRows(string $out): array
{
    return array_map(fn ($line) => json_decode($line, true), file($out.'/train.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

function trExportedMeta(string $out): array
{
    return array_map(fn ($line) => json_decode($line, true), file($out.'/manifest.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

it('writes one metadata line per row, with hashed identities and no text', function () {
    trExportCorpus($this->out);

    $rows = trExportedRows($this->out);

    $meta = trExportedMeta($this->out);

    expect($rows)->not->toBeEmpty()
        ->and($meta)->toHaveCount(count($rows))
        ->and(array_keys($rows[0]))->toBe(['prompt', 'completion'])
        ->and($meta[0]['conversation'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($meta[0]['tenant'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($meta[0]['consent'])->toBe('tenant opt-in under terms v4')
        ->and($meta[0]['system_version'])->toBe(SupportPrompt::FINE_TUNED_VERSION)
        ->and(array_key_exists('prompt', $meta[0]))->toBeFalse()
        ->and(file_get_contents($this->out.'/manifest.jsonl'))->not->toContain('conversation_id');
});

it('hashes a conversation id with the key, so the same id and the same key find its examples', function () {
    trExportCorpus($this->out);

    $hash = hash_hmac('sha256', '900001', 'test-hash-key');

    expect(collect(trExportedMeta($this->out))->pluck('conversation')->contains($hash))->toBeTrue();
});

it('exports only what is eligible: no tenant that is off, no excluded user, no residency tenant, no thumbs-down', function () {
    trExportCorpus($this->out);

    $hashes = collect(trExportedMeta($this->out))->pluck('conversation')->unique();

    $off = Message::withoutGlobalScopes()->where('role', 'assistant')->whereNotNull('question')->whereIn('tenant_id', [5, 7, 10])->pluck('conversation_id');

    expect($off)->not->toBeEmpty();

    foreach ($off as $id) {
        expect($hashes->contains(hash_hmac('sha256', (string) $id, 'test-hash-key')))->toBeFalse();
    }
});

it('uses the fine-tuned system message, without the canary, and the same slots as the live prompt', function () {
    config(['ai.security.canary' => 'CANARY-PHRASE-NEVER-TRAIN']);

    trExportCorpus($this->out);

    $row = trExportedRows($this->out)[0];

    expect($row['prompt'][0])->toBe(['role' => 'system', 'content' => SupportPrompt::fineTuned()])
        ->and(file_get_contents($this->out.'/train.jsonl'))->not->toContain('CANARY-PHRASE-NEVER-TRAIN')
        ->and(collect($row['prompt'])->pluck('role')->all()[1])->toBe('user')
        ->and(json_encode($row['prompt']))->toContain('untrusted-')->toContain('customer_message')
        ->and(strlen($row['prompt'][0]['content']))->toBeLessThan(strlen(SupportPrompt::base()));
});

it('keeps the tool traffic of the turn between the question and the reply', function () {
    trExportCorpus($this->out);

    $roles = collect(trExportedRows($this->out)[0]['prompt'])->pluck('role')->all();

    expect($roles)->toContain('assistant')->toContain('tool')
        ->and(array_search('assistant', $roles))->toBeGreaterThan(array_search('user', $roles));
});

it('trains on the agent\'s edit where there is one, else the sent reply', function () {
    trExportCorpus($this->out);

    $sources = collect(trExportedMeta($this->out))->pluck('source')->unique()->sort()->values()->all();

    $rows = trExportedRows($this->out);

    $meta = trExportedMeta($this->out);

    $edit = collect($meta)->search(fn ($m) => $m['source'] === 'edits');

    expect($sources)->toBe(['edits', 'production'])
        ->and($rows[$edit]['completion'][0]['content'])->toContain('If anything looks wrong');
});

it('replaces personal data in the question with invented values, consistently', function () {
    trExportCorpus($this->out);

    $text = file_get_contents($this->out.'/train.jsonl');

    expect($text)->not->toContain('dana.whitfield@example.net')->not->toContain('priya.raman@example.org')->not->toContain('+44 20 7946 0958')
        ->and(collect(trExportedRows($this->out))->contains(fn ($row) => str_contains(json_encode($row), 'Hi, this is Dana Whitfield')))->toBeTrue();
});

it('writes the same file twice for the same key and data', function () {
    trExportCorpus($this->out);

    $first = file_get_contents($this->out.'/train.jsonl');

    trExportCorpus($this->out);

    $untrusted = fn (string $text) => preg_replace('/untrusted-[0-9a-f]+/', 'untrusted-T', $text);

    expect($untrusted(file_get_contents($this->out.'/train.jsonl')))->toBe($untrusted($first));
});

it('refuses a tenant whose training use is off, even by name', function () {
    $name = Tenant::find(5)->name;

    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out, '--patterns-only' => true, '--tenant' => $name])
        ->expectsOutputToContain('has not turned training use on')
        ->assertExitCode(1);

    expect(File::exists($this->out.'/train.jsonl'))->toBeFalse();
});

it('exports one tenant that did opt in', function () {
    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out, '--patterns-only' => true, '--tenant' => '1'])->assertExitCode(0);

    expect(collect(trExportedMeta($this->out))->pluck('tenant')->unique())->toHaveCount(1);
});

it('refuses to run without the hash key', function () {
    config(['ai.training.hash_key' => '']);

    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out, '--patterns-only' => true])
        ->expectsOutputToContain('Set AI_TRAINING_HASH_KEY')
        ->assertExitCode(1);
});

it('refuses to run when names cannot be screened, unless told to export patterns only', function () {
    $ner = new FakeNer;

    $ner->up = false;

    app()->instance(Ner::class, $ner);

    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out])
        ->expectsOutputToContain('cannot be screened')
        ->assertExitCode(1);

    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out, '--patterns-only' => true])->assertExitCode(0);
});

it('screens names too when the detector is there', function () {
    app()->instance(Ner::class, FakeNer::knowing(['Dana Whitfield' => 'PERSON']));

    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out])->assertExitCode(0);

    expect(file_get_contents($this->out.'/train.jsonl'))->not->toContain('Dana Whitfield');
});

it('limits a region export to tenants with no residency or that region', function () {
    $this->artisan('dataset:export', ['category' => 'billing', '--out' => $this->out, '--patterns-only' => true, '--region' => 'eu'])->assertExitCode(0);

    $eu = count(trExportedMeta($this->out));

    trExportCorpus($this->out);

    expect($eu)->toBeGreaterThan(count(trExportedMeta($this->out)));
});
