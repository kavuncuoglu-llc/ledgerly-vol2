<?php

use App\Evals\GoldenSet;
use App\Evals\RoleComparison\TripwireRunner;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Chapter 41, Topic 6: eval:compare over a role and its base, the tripwire for a hosted model, and adr:append.
beforeEach(function () {
    config(['ai.eval.fixture_customers' => PHP_INT_MAX, 'ai.eval.enabled' => true]);

    $customer = Customer::factory()->create(['name' => 'Acme Studios']);

    User::factory()->owner($customer)->create();

    $this->dir = sys_get_temp_dir().'/ft-compare-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    $cases = collect(['a', 'b', 'c'])->map(fn ($id) => ['id' => "billing-{$id}", 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => "Why was INV-{$id} charged a fee?", 'expected_behavior' => 'answer', 'must_include' => ['1.5%']]);

    File::put($this->dir.'/cases.jsonl', $cases->map(fn ($c) => json_encode($c))->implode("\n")."\n");

    app()->instance(GoldenSet::class, new GoldenSet($this->dir));

    File::deleteDirectory(storage_path('eval'));

    config([
        'ai.providers.frontier-billing-ft.base_url' => 'https://api.example.com/v1',
        'ai.providers.frontier-billing-ft.model' => 'ft:fake-billing:abc',
        'ai.providers.frontier-billing-ft.privacy.dpa_signed' => true,
        'ai.providers.frontier.base_url' => 'https://api.example.com/v1',
        'ai.providers.frontier.model' => 'base-x',
        'ai.providers.frontier.api_key' => 'sk-test-not-real',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::deleteDirectory(storage_path('eval'));
});

it('runs each role on the billing slice, prints the table and saves the result', function () {
    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    $this->artisan('eval:compare', ['--roles' => 'local,frontier-billing-ft', '--with' => 'gates'])
        ->expectsOutputToContain('golden:billing · 3 cases · 2 roles')
        ->expectsOutputToContain('Golden pass rate')
        ->expectsOutputToContain('Required facts present')
        ->assertExitCode(0);

    $saved = json_decode(File::get(storage_path('eval/last-compare.json')), true);

    expect($saved['cases'])->toBe(3)
        ->and($saved['results'][0]['metrics']['golden_pass_rate'])->toEqual(1)
        ->and($saved['results'][1]['role'])->toBe('frontier-billing-ft')
        ->and($saved['results'][1]['metrics'])->toHaveKeys(['golden_pass_rate', 'required_facts_rate', 'input_tokens_per_answer', 'cost_per_answer_usd']);
});

it('leaves out a role that is not set up and says so', function () {
    config(['ai.providers.frontier-billing-ft.model' => null]);

    Ai::fake()->for('support.reply')->respondToEverythingWith('The fee is 1.5% a month.');

    $this->artisan('eval:compare', ['--roles' => 'local,frontier-billing-ft', '--with' => 'gates'])
        ->expectsOutputToContain('frontier-billing-ft: not measured, frontier-billing-ft is not set up')
        ->assertExitCode(0);
});

it('refuses a check or a set it does not know', function () {
    $this->artisan('eval:compare', ['--with' => 'vibes'])->expectsOutputToContain('[vibes] is not a check')->assertExitCode(1);

    $this->artisan('eval:compare', ['--set' => 'tests'])->expectsOutputToContain('--set takes golden')->assertExitCode(1);

    $this->artisan('eval:compare', ['--set' => 'golden:nothing'])->expectsOutputToContain('No golden cases in golden:nothing')->assertExitCode(1);
});

it('runs the hosted tripwire on generation tasks only, through the chat endpoint, and compares with the base', function () {
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        $output = $process->command[array_search('--output_path', $process->command) + 1];

        $score = str_contains($command, 'ft:fake-billing') ? 0.60 : 0.62;

        File::ensureDirectoryExists("{$output}/model");

        File::put("{$output}/model/results_1.json", json_encode(['results' => ['gsm8k' => ['exact_match,strict-match' => $score], 'ifeval' => ['prompt_level_strict_acc,none' => $score + 0.1]]]));

        return Process::result('');
    });

    $drop = app(TripwireRunner::class)->largestDrop('frontier-billing-ft', 'generation');

    expect($drop['drop'])->toBe(2.0)->and($drop['tasks'])->toEqual(['gsm8k' => -2.0, 'ifeval' => -2.0]);

    Process::assertRan(fn ($process) => in_array('local-chat-completions', $process->command) && in_array('gsm8k,ifeval', $process->command) && in_array('--apply_chat_template', $process->command));
});

it('will not run the multiple-choice tasks on a hosted model', function () {
    expect(fn () => app(TripwireRunner::class)->largestDrop('frontier-billing-ft', 'all'))->toThrow(InvalidArgumentException::class, 'only tripwire:generation can run on it');
});

it('writes the last comparison into the decision record as a section, replacing the same section the next time', function () {
    $record = $this->dir.'/adr.md';

    File::put($record, "# Decision\n\n## Success criteria\n\nText.\n\n## Exit\n\nStop.\n");

    File::ensureDirectoryExists(storage_path('eval'));

    File::put(storage_path('eval/last-compare.json'), json_encode([
        'set' => 'golden:billing', 'cases' => 61, 'checks' => ['gates'], 'ran_on' => '2026-11-12',
        'results' => [
            ['role' => 'frontier', 'provider' => 'frontier', 'model' => 'base-x', 'metrics' => ['golden_pass_rate' => 0.96, 'required_facts_rate' => 0.93, 'input_tokens_per_answer' => 3500, 'cost_per_answer_usd' => 0.013], 'notes' => [], 'skipped' => null],
            ['role' => 'frontier-billing-ft', 'provider' => 'frontier-billing-ft', 'model' => '', 'metrics' => [], 'notes' => [], 'skipped' => 'frontier-billing-ft is not set up'],
        ],
    ]));

    $this->artisan('adr:append', ['record' => $record, '--section' => 'Hosted baseline', '--note' => 'Fake driver: plumbing only.'])->expectsOutputToContain('section [Hosted baseline] written')->assertExitCode(0);

    $text = File::get($record);

    expect($text)->toContain("## Hosted baseline\n\nFake driver: plumbing only.")
        ->and($text)->toContain('| Golden pass rate | 96% | not measured |')
        ->and($text)->toContain('| Input tokens per answer | ~3,500 | not measured |')
        ->and($text)->toContain('frontier-billing-ft was not measured: frontier-billing-ft is not set up.')
        ->and(strpos($text, '## Hosted baseline'))->toBeLessThan(strpos($text, '## Exit'));

    $this->artisan('adr:append', ['record' => $record, '--section' => 'Hosted baseline'])->assertExitCode(0);

    expect(substr_count(File::get($record), '## Hosted baseline'))->toBe(1)->and(File::get($record))->not->toContain('Fake driver: plumbing only.');
});

it('says what is missing for adr:append', function () {
    $this->artisan('adr:append', ['record' => $this->dir.'/none.md', '--section' => 'x'])->expectsOutputToContain('No decision record')->assertExitCode(1);

    File::put($this->dir.'/adr.md', '# x');

    $this->artisan('adr:append', ['record' => $this->dir.'/adr.md', '--section' => 'x'])->expectsOutputToContain('No comparison at')->assertExitCode(1);

    $this->artisan('adr:append', ['record' => $this->dir.'/adr.md'])->expectsOutputToContain('Name the section')->assertExitCode(1);
});
