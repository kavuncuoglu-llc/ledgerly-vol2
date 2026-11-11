<?php

use App\Evals\FailureModes\Backlog;
use App\Evals\GoldenSet;
use App\Facades\Ai;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// Chapter 38, Topic 8: eval:failure-modes over a results file, so no model runs. The golden set is a temporary directory.
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/failure-modes-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir);

    $cases = [
        ['id' => 'billing-a', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'Why is there a fee on INV-4471?', 'expected_behavior' => 'answer', 'expected_chunks' => ['help:billing/late-fees#how-the-fee-is-calculated'], 'must_include' => ['INV-4471']],
        ['id' => 'billing-b', 'category' => 'billing', 'source' => 'synthetic', 'customer' => 'acme', 'question' => 'How do I change my plan?', 'expected_behavior' => 'answer', 'expected_chunks' => ['help:billing/change-your-plan#upgrading']],
        ['id' => 'billing-c', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'Do you support crypto?', 'expected_behavior' => 'decline'],
        ['id' => 'production-old', 'category' => 'billing', 'source' => 'production', 'added' => '2026-01-01', 'customer' => 'acme', 'question' => 'An old production failure', 'expected_behavior' => 'answer', 'expected_chunks' => ['help:billing/late-fees#how-the-fee-is-calculated']],
        ['id' => 'howto-a', 'category' => 'howto', 'source' => 'human', 'customer' => 'acme', 'question' => 'How do I add a customer?', 'expected_behavior' => 'answer'],
    ];

    File::put($this->dir.'/cases.jsonl', collect($cases)->map(fn (array $row) => json_encode($row))->implode("\n")."\n");

    app()->instance(GoldenSet::class, new GoldenSet($this->dir));

    $answer = fn (string $text, array $retrieved, string $behavior = 'answer') => ['answer' => $text, 'behavior' => $behavior, 'citations' => [], 'retrieved' => $retrieved, 'sources' => [['label' => 'help:billing/late-fees#how-the-fee-is-calculated', 'text' => 'The fee for INV-4471 is 1.5% a month.']]];

    $failing = [
        ['id' => 'billing-a', 'answer' => $answer('A fee was added.', ['help:billing/late-fees#how-the-fee-is-calculated'])],
        ['id' => 'billing-b', 'answer' => $answer('Open Settings.', ['help:billing/late-fees#how-the-fee-is-calculated'])],
        ['id' => 'billing-c', 'answer' => $answer('Yes we do.', [])],
        ['id' => 'production-old', 'answer' => $answer('Nothing.', [])],
        ['id' => 'howto-a', 'answer' => $answer('Nothing.', [])],
    ];

    File::put($this->dir.'/results.json', json_encode(['failing' => $failing]));
});

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::delete($this->dir.'-backlog.jsonl');
});

it('sorts the failing cases of one category into buckets and prints the table', function () {
    $this->artisan('eval:failure-modes', ['--from' => $this->dir.'/results.json', '--no-backlog' => true])
        ->expectsOutputToContain('3 failing cases (golden 3, production 0)')
        ->expectsOutputToContain('behavior and format')
        ->expectsOutputToContain('retrieval miss')
        ->expectsOutputToContain('coverage gap')
        ->expectsOutputToContain('Fine-tuning is for the first row only')
        ->assertExitCode(0);
});

it('leaves production cases older than the window out', function () {
    $this->artisan('eval:failure-modes', ['--from' => $this->dir.'/results.json', '--no-backlog' => true, '--json' => true, '--since' => '3650d'])
        ->expectsOutputToContain('production-old')
        ->assertExitCode(0);
});

it('writes retrieval misses and coverage gaps to the backlog, once per case', function () {
    $path = $this->dir.'-backlog.jsonl';

    $this->artisan('eval:failure-modes', ['--from' => $this->dir.'/results.json', '--backlog' => $path])->assertExitCode(0);

    $this->artisan('eval:failure-modes', ['--from' => $this->dir.'/results.json', '--backlog' => $path])->assertExitCode(0);

    $items = collect((new Backlog($path))->read())->pluck('kind', 'case')->all();

    expect($items)->toBe(['billing-b' => 'retrieval', 'billing-c' => 'coverage']);
});

it('sends what the checks cannot place to the judge and marks the verdict as judged', function () {
    $case = ['id' => 'billing-d', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'Who is the owner?', 'expected_behavior' => 'answer', 'must_include' => ['Dana Whitfield']];

    File::append($this->dir.'/cases.jsonl', json_encode($case)."\n");

    File::put($this->dir.'/unclear.json', json_encode(['failing' => [['id' => 'billing-d', 'answer' => ['answer' => 'Someone.', 'behavior' => 'answer', 'citations' => [], 'retrieved' => [], 'sources' => []]]]]));

    Ai::fake()->for('eval.failure-modes')->respondWith('arithmetic');

    Artisan::call('eval:failure-modes', ['--from' => $this->dir.'/unclear.json', '--no-backlog' => true, '--judge' => true, '--json' => true]);

    $report = json_decode(Artisan::output(), true);

    expect($report['cases'][0])->toMatchArray(['case' => 'billing-d', 'bucket' => 'arithmetic', 'judged' => true]);
});

it('says so when a judge is asked and cannot decide', function () {
    File::append($this->dir.'/cases.jsonl', json_encode(['id' => 'billing-d', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'Who?', 'expected_behavior' => 'answer', 'must_include' => ['Dana']])."\n");

    File::put($this->dir.'/unclear.json', json_encode(['failing' => [['id' => 'billing-d', 'answer' => ['answer' => 'Someone.', 'behavior' => 'answer', 'citations' => [], 'retrieved' => [], 'sources' => []]]]]));

    Ai::fake()->for('eval.failure-modes')->respondWith('unsure');

    $this->artisan('eval:failure-modes', ['--from' => $this->dir.'/unclear.json', '--no-backlog' => true, '--judge' => true])
        ->expectsOutputToContain('unclear (judged, then reviewed)')
        ->assertExitCode(0);
});

it('refuses another feature, a category with no cases, and a bad window', function () {
    $this->artisan('eval:failure-modes', ['--feature' => 'triage.classify'])->expectsOutputToContain('Only support.reply')->assertExitCode(1);

    $this->artisan('eval:failure-modes', ['--category' => 'nothing'])->expectsOutputToContain('No golden cases')->assertExitCode(1);

    $this->artisan('eval:failure-modes', ['--since' => 'last week'])->expectsOutputToContain('number of days')->assertExitCode(1);
});
