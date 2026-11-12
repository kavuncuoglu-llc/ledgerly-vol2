<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// Chapters 47 and 48: a decision record starts from one skeleton, with the review date Chapter 50's quarterly review reads.

beforeEach(function () {
    File::delete(base_path('docs/adr/test-only-decision.md'));
});

afterEach(function () {
    File::delete(base_path('docs/adr/test-only-decision.md'));
});

it('writes the skeleton with a status line that carries the decision, owner and review date', function () {
    $exit = Artisan::call('adr:new', ['title' => 'Test only decision', '--decision' => 'not-now', '--review' => '2027-02-01', '--owner' => 'billing team', '--slug' => 'test-only-decision']);

    $record = file_get_contents(base_path('docs/adr/test-only-decision.md'));

    expect($exit)->toBe(0)
        ->and($record)->toStartWith("# Test only decision\n\nStatus: not-now · Owner: billing team · Review: 2027-02-01\n")
        ->and($record)->toContain('## Context', '## Decision', '## What would change this');
});

it('defaults the review to ninety days out', function () {
    Artisan::call('adr:new', ['title' => 'Test only decision', '--slug' => 'test-only-decision']);

    expect(file_get_contents(base_path('docs/adr/test-only-decision.md')))->toContain('Review: '.now()->addDays(90)->toDateString());
});

it('never rewrites a record: a new decision supersedes it', function () {
    Artisan::call('adr:new', ['title' => 'Test only decision', '--slug' => 'test-only-decision']);

    $exit = Artisan::call('adr:new', ['title' => 'Test only decision', '--slug' => 'test-only-decision']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('exists');
});

it('validates what it writes', function (array $arguments, string $message) {
    $exit = Artisan::call('adr:new', $arguments);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain($message)
        ->and(File::exists(base_path('docs/adr/test-only-decision.md')))->toBeFalse();
})->with([
    'a path in the slug' => [['title' => 'x', '--slug' => '../../etc/passwd'], 'is not a file name'],
    'an uppercase slug' => [['title' => 'x', '--slug' => 'Test_Only'], 'is not a file name'],
    'an unknown decision' => [['title' => 'x', '--decision' => 'maybe', '--slug' => 'test-only-decision'], 'is not a decision'],
    'a bad review date' => [['title' => 'x', '--review' => '2027-13-45', '--slug' => 'test-only-decision'], 'is not a date'],
    'an empty title' => [['title' => '   '], 'Give the decision a title'],
    'a long title' => [['title' => str_repeat('a', 121)], '120 characters'],
]);

it('puts an eval:compare result in the record when asked', function () {
    File::ensureDirectoryExists(storage_path('eval'));

    $result = ['set' => 'golden:support', 'cases' => 2, 'checks' => ['gates'], 'ran_on' => '2026-11-11', 'results' => [
        ['role' => 'raw', 'provider' => 'local', 'model' => 'm', 'metrics' => ['golden_pass_rate' => 0.5, 'ttft_p50_s' => 0.123], 'notes' => [], 'skipped' => null],
    ]];

    $path = tempnam(sys_get_temp_dir(), 'cmp');

    File::put($path, json_encode($result));

    Artisan::call('adr:new', ['title' => 'Test only decision', '--slug' => 'test-only-decision', '--from' => $path]);

    expect(file_get_contents(base_path('docs/adr/test-only-decision.md')))->toContain('## Measurements', '| Golden pass rate | 50% |', '| Time to first token, p50 | 0.123 s |');

    File::delete($path);
});
