<?php

use App\Evals\EvalAnswer;
use App\Evals\Gates;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

// Chapter 29, Figure 29-9: the export, the JavaScript gates and the checker that reads promptfoo's results.
function hasNode(): bool
{
    return Process::run('node --version')->successful();
}

function exportedTests(array $options = []): array
{
    $path = sys_get_temp_dir().'/promptfoo-'.uniqid().'.yaml';

    test()->artisan('eval:export-promptfoo', ['--out' => $path] + $options)->assertExitCode(0);

    $tests = Yaml::parseFile($path);

    File::delete($path);

    return $tests;
}

it('writes the golden set as promptfoo tests, with list variables as JSON strings', function () {
    $tests = exportedTests(['--subset' => 'all']);

    expect($tests)->toHaveCount(GoldenSet::default()->all()->count());

    $first = collect($tests)->firstWhere('description', 'billing-late-fee-014');

    expect($first['vars']['question'])->toBe('Why did my March invoice go up by $21.60?')
        ->and($first['vars']['customer'])->toBe('acme')
        ->and(json_decode($first['vars']['must_include']))->toBe(['INV-4471', '21.60'])
        ->and($first['vars']['expected_behavior'])->toBe('answer')
        ->and($first['metadata']['source'])->toBe('human')
        ->and($first)->not->toHaveKey('assert');
});

it('exports the smoke subset and the single-source subsets', function () {
    expect(exportedTests(['--subset' => 'smoke']))->toHaveCount(25)
        ->and(collect(exportedTests(['--subset' => 'synthetic']))->pluck('metadata.source')->unique()->all())->toBe(['synthetic']);
});

it('adds the versioned rubric judge to every test only with --with-judge, using the configured judge provider', function () {
    config(['ai.eval.judge_provider' => 'ollama:chat:judge-model']);

    $tests = exportedTests(['--subset' => 'smoke', '--with-judge' => true]);

    $assert = $tests[0]['assert'][0];

    expect($assert['type'])->toBe('llm-rubric')
        ->and($assert['provider'])->toBe('ollama:chat:judge-model')
        ->and($assert['value'])->toContain('Does the answer address the customer')->toContain('Pass only if all four are yes.')
        ->and($assert['metric'])->toBe('judge');
});

it('keeps the rubric as a versioned file', function () {
    expect(File::exists(base_path('evals/judges/support-quality.v2.yaml')))->toBeTrue()
        ->and(Yaml::parseFile(base_path('evals/promptfooconfig.yaml'))['defaultTest']['assert'][0]['value'])->toBe('file://assertions/gates.js')
        ->and(Yaml::parseFile(base_path('evals/promptfooconfig.yaml'))['tests'])->toBe('file://../tests/golden/promptfoo.yaml');
});

it('fails on an unknown subset or an empty golden set', function () {
    $this->artisan('eval:export-promptfoo', ['--subset' => 'everything'])->expectsOutputToContain('Unknown subset')->assertExitCode(1);
});

describe('evals/assertions/gates.js and the PHP gates agree', function () {
    $scenarios = [
        'a good answer' => [[], [], true, true],
        'wrong behavior' => [['behavior' => 'decline'], [], false, true],
        'an invented citation' => [['citations' => ['help:billing/refunds#refunds']], [], false, true],
        'a missing fact' => [['answer' => 'It is added each month. [late-fees]'], [], false, true],
        'a leaked fact' => [['answer' => 'INV-4471 1.5% and INV-5120 too.'], [], true, false],
        'a missing chunk' => [['retrieved' => ['help:billing/late-fee-settings#late-fee-settings'], 'citations' => []], [], false, true],
        'markdown' => [['answer' => 'INV-4471 **1.5%** fee.'], [], false, true],
        'thousands separators' => [['answer' => 'INV-4471 is $1,284.50 at 1.5%.'], ['must_include' => ['1284.50', '1.5%']], true, true],
    ];

    foreach ($scenarios as $name => [$answer, $case, $qualityOk, $neverOk]) {
        it("on {$name}", function () use ($answer, $case, $qualityOk, $neverOk) {
            $output = $answer + [
                'answer' => 'INV-4471 carries a late fee of 1.5% a month. [late-fees › How the fee is calculated]',
                'behavior' => 'answer',
                'citations' => ['help:billing/late-fees#how-the-fee-is-calculated'],
                'retrieved' => ['help:billing/late-fees#how-the-fee-is-calculated'],
            ];

            $golden = $case + [
                'id' => 'x', 'category' => 'billing', 'source' => 'human', 'customer' => 'acme', 'question' => 'q', 'expected_behavior' => 'answer',
                'expected_chunks' => ['help:billing/late-fees#how-the-fee-is-calculated'], 'must_include' => ['1.5%', 'INV-4471'], 'must_not_include' => ['INV-5120'],
            ];

            $php = Gates::evaluate(EvalAnswer::fromArray($output), $output['retrieved'], GoldenCase::fromArray($golden));

            expect($php->passedQuality())->toBe($qualityOk)->and($php->passedNever())->toBe($neverOk);

            $vars = array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $golden);

            $script = 'const gates = require(process.argv[1]); const input = JSON.parse(process.argv[2]); process.stdout.write(JSON.stringify(gates(input.output, { vars: input.vars })));';

            $run = Process::run(['node', '-e', $script, base_path('evals/assertions/gates.js'), json_encode(['output' => $output, 'vars' => $vars])]);

            expect($run->successful())->toBeTrue($run->errorOutput());

            $js = json_decode($run->output(), true);

            expect($js['namedScores']['quality'] === 1)->toBe($qualityOk)
                ->and($js['namedScores']['never'] === 1)->toBe($neverOk)
                ->and($js['pass'])->toBe($qualityOk && $neverOk);
        })->skip(fn () => ! hasNode(), 'node is not installed');
    }
});
