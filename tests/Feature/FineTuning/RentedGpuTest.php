<?php

use App\Ai\Ledger\TrainingLedger;
use App\Ai\Training\Dataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\FineTuningWorld;
use Tests\Support\TinyDataset;

// Chapter 43, Topic 6: the manifest decides which provider and region may hold a dataset, and a rented session writes its own ledger rows.
beforeEach(function () {
    $this->dataset = FineTuningWorld::dataset();

    $manifest = $this->dataset->readJson('manifest.json');

    $manifest['composition'] = ['production' => 4, 'edits' => 2, 'synthetic' => 2];

    $this->dataset->writeJson('manifest.json', $manifest);
});

afterEach(fn () => TinyDataset::cleanUp());

it('allows a dataset with no customer data on any host, and refuses one with it until a decision is recorded', function () {
    $clean = Dataset::named('billing-clean');

    File::copyDirectory($this->dataset->path(), $clean->path());

    $manifest = $clean->readJson('manifest.json');

    $manifest['composition'] = ['synthetic' => 3, 'replay' => 1, 'exemplars' => 1];

    $clean->writeJson('manifest.json', $manifest);

    $this->artisan('dataset:allows', ['dataset' => 'billing-clean', '--provider' => 'rented', '--region' => 'eu'])->expectsOutputToContain('manifest allows rented in eu')->assertExitCode(0);

    $this->artisan('dataset:allows', ['dataset' => 'billing-v1', '--provider' => 'rented', '--region' => 'eu'])
        ->expectsOutputToContain('production and edits hold customer data and the manifest of billing-v1 does not allow rented in eu')
        ->assertExitCode(1);
});

it('allows customer-derived data only where the decision was recorded, in that region, for a provider whose own answers allow it', function () {
    config(['ai.providers.rented' => ['privacy' => ['trains_on_api_data' => false, 'dpa_signed' => true]]]);

    $this->artisan('dataset:allow', ['dataset' => 'billing-v1', '--provider' => 'rented', '--region' => 'eu', '--reason' => 'DPA signed 2026-10-01; section 4 of the tenants\' terms'])->assertExitCode(0);

    $this->artisan('dataset:allows', ['dataset' => 'billing-v1', '--provider' => 'rented', '--region' => 'eu'])->assertExitCode(0);

    $this->artisan('dataset:allows', ['dataset' => 'billing-v1', '--provider' => 'rented', '--region' => 'us'])->expectsOutputToContain('does not allow rented in us')->assertExitCode(1);

    config(['ai.providers.rented.privacy.dpa_signed' => false]);

    $this->artisan('dataset:allows', ['dataset' => 'billing-v1', '--provider' => 'rented', '--region' => 'eu'])->expectsOutputToContain('has no signed data processing agreement')->assertExitCode(1);
});

it('finds the private Hub copy by repository and revision, and refuses one no manifest records', function () {
    $manifest = $this->dataset->readJson('manifest.json');

    $manifest['hub'] = ['repository' => 'ledgerly/billing-synthetic', 'revision' => 'a41c09e1234', 'files' => ['synthetic.jsonl'], 'private' => true];

    $this->dataset->writeJson('manifest.json', $manifest);

    $this->artisan('dataset:allows', ['dataset' => 'billing-synthetic@a41c09e', '--provider' => 'rented', '--region' => 'eu'])->assertExitCode(0);

    $this->artisan('dataset:allows', ['dataset' => 'ledgerly/billing-synthetic@a41c09e', '--provider' => 'rented'])->assertExitCode(0);

    $this->artisan('dataset:allows', ['dataset' => 'billing-synthetic@ffffff', '--provider' => 'rented'])->expectsOutputToContain('No published dataset has the Hub copy billing-synthetic@ffffff')->assertExitCode(1);
});

it('says what is missing', function () {
    $this->artisan('dataset:allows', ['dataset' => 'billing-v1'])->expectsOutputToContain('Name the provider')->assertExitCode(1);

    $this->artisan('dataset:allows', ['dataset' => 'billing-v9', '--provider' => 'x'])->expectsOutputToContain('has no manifest.json')->assertExitCode(1);

    expect(Dataset::named('billing-v1')->exists('manifest.json'))->toBeTrue();
});

it('writes a session\'s ledger rows: one when it opens at no cost, one when it ends at the hours billed times the rate, each once', function () {
    $this->artisan('ai:training-session', ['action' => 'open', '--session' => 's1', '--provider' => 'rented', '--gpu' => 'a100-80gb', '--rate' => '1.89'])->expectsOutputToContain('training session row written (open)')->assertExitCode(0);

    $this->artisan('ai:training-session', ['action' => 'close', '--session' => 's1', '--provider' => 'rented', '--gpu' => 'a100-80gb', '--hours' => '2.1', '--rate' => '1.89', '--note' => 'finished'])->assertExitCode(0);

    $this->artisan('ai:training-session', ['action' => 'close', '--session' => 's1', '--provider' => 'rented', '--gpu' => 'a100-80gb', '--hours' => '2.1', '--rate' => '1.89'])->expectsOutputToContain('already written')->assertExitCode(0);

    $rows = DB::table('ai_ledger')->where('feature', TrainingLedger::SESSION_FEATURE)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->entry_id->toBe('session:s1:open')->cost_micro_usd->toBe(0)->kind->toBe('training')
        ->and((int) $rows[1]->cost_micro_usd)->toBe(3_969_000)
        ->and($rows[1]->reason)->toContain('2.1 h at $1.89 an hour. finished')
        ->and($rows[1]->team)->toBe('platform');
});

it('refuses an action it does not know, and a session with no name', function () {
    $this->artisan('ai:training-session', ['action' => 'pause'])->expectsOutputToContain('open or close')->assertExitCode(1);

    $this->artisan('ai:training-session', ['action' => 'open', '--provider' => 'x', '--gpu' => 'y'])->expectsOutputToContain('Name the session')->assertExitCode(1);
});
