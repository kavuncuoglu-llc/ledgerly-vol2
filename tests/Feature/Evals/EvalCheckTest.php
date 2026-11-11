<?php

use App\Evals\ResultsChecker;
use Illuminate\Support\Facades\File;

// Chapter 29: eval:check reads promptfoo's results.json (the shape promptfoo 0.125 writes) and applies 95% for quality gates and zero for never-gates.
function promptfooRow(string $id, bool $quality, bool $never, string $reason = 'ok', string $source = 'human', ?bool $judge = null): array
{
    $components = [['pass' => $quality && $never, 'score' => 1, 'reason' => $reason, 'assertion' => ['type' => 'javascript']]];

    if ($judge !== null) {
        $components[] = ['pass' => $judge, 'score' => (int) $judge, 'reason' => 'rubric', 'assertion' => ['type' => 'llm-rubric', 'metric' => 'judge']];
    }

    return [
        'success' => $quality && $never,
        'namedScores' => ['quality' => (int) $quality, 'never' => (int) $never],
        'gradingResult' => ['pass' => $quality && $never, 'reason' => $reason, 'namedScores' => ['quality' => (int) $quality, 'never' => (int) $never], 'componentResults' => $components],
        'response' => ['output' => ['answer' => 'x']],
        'testCase' => ['description' => $id, 'metadata' => ['source' => $source]],
    ];
}

function writeResults(array $rows): string
{
    $path = sys_get_temp_dir().'/results-'.uniqid().'.json';

    File::put($path, json_encode(['evalId' => 'e', 'results' => ['version' => 3, 'results' => $rows]]));

    return $path;
}

it('passes at 97% quality and no never-gate failures', function () {
    $rows = collect(range(1, 100))->map(fn (int $i) => promptfooRow("case-{$i}", $i > 3, true, $i > 3 ? 'ok' : 'missing 1.5%'))->all();

    $this->artisan('eval:check', ['results' => writeResults($rows), '--out' => sys_get_temp_dir().'/check.json'])
        ->expectsOutputToContain('quality gates   97 / 100 pass all   (97.0%  ≥ 95% required)   ✓')
        ->expectsOutputToContain('never gates     100 / 100 pass       (0 allowed failures)       ✓')
        ->assertExitCode(0);
});

it('fails below 95% quality', function () {
    $rows = collect(range(1, 100))->map(fn (int $i) => promptfooRow("case-{$i}", $i > 6, true, 'missing 1.5%'))->all();

    $this->artisan('eval:check', ['results' => writeResults($rows), '--out' => sys_get_temp_dir().'/check.json'])->expectsOutputToContain('94.0%')->assertExitCode(1);
});

it('fails on a single never-gate failure, however good the rest is', function () {
    $rows = collect(range(1, 100))->map(fn (int $i) => promptfooRow("case-{$i}", true, $i !== 50, $i === 50 ? 'NEVER: leaked INV-5120' : 'ok'))->all();

    $this->artisan('eval:check', ['results' => writeResults($rows), '--out' => sys_get_temp_dir().'/check.json'])
        ->expectsOutputToContain('never gates     99 / 100 pass       (0 allowed failures)       ✗')
        ->expectsOutputToContain('case-50')
        ->assertExitCode(1);

    $this->artisan('eval:check', ['results' => writeResults($rows), '--never' => 1, '--out' => sys_get_temp_dir().'/check.json'])->assertExitCode(0);
});

it('counts a provider error as a quality failure and reports pass rates per source and the judge apart', function () {
    $rows = [
        promptfooRow('a', true, true, judge: true),
        promptfooRow('b', true, true, source: 'synthetic', judge: false),
        ['success' => false, 'error' => 'connect ECONNREFUSED', 'response' => [], 'testCase' => ['description' => 'c', 'metadata' => ['source' => 'synthetic']]],
    ];

    $summary = (new ResultsChecker)->check(['results' => ['results' => $rows]], 0.95, 0);

    expect($summary['quality_passes'])->toBe(2)
        ->and($summary['failing'])->toHaveCount(1)
        ->and($summary['failing'][0]['id'])->toBe('c')
        ->and($summary['by_source']['synthetic']['quality_pass_rate'])->toBe(0.5)
        ->and($summary['judge_cases'])->toBe(2)
        ->and($summary['judge_pass_rate'])->toBe(0.5);
});

it('falls back to the reason prefix when a file has no named scores', function () {
    $row = ['success' => false, 'gradingResult' => ['pass' => false, 'reason' => 'NEVER: leaked INV-5120'], 'response' => ['output' => 'x'], 'testCase' => ['description' => 'd']];

    $summary = (new ResultsChecker)->check(['results' => ['results' => [$row]]], 0.95, 0);

    expect($summary['never_failures'])->toBe(1);
});

it('says so when the file is not promptfoo output or does not exist', function () {
    $this->artisan('eval:check', ['results' => '/nonexistent/results.json'])->expectsOutputToContain('No results file')->assertExitCode(1);

    $path = sys_get_temp_dir().'/not-results.json';

    File::put($path, '{"hello":"world"}');

    $this->artisan('eval:check', ['results' => $path])->expectsOutputToContain('no promptfoo results')->assertExitCode(1);
});
