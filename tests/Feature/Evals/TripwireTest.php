<?php

use Illuminate\Support\Facades\File;

// Chapter 30, Figure 30-6: the same four benchmarks before and after a fine-tune; any drop above --max-drop points trips.
function lmEvalRun(array $scores, string $model = 'Qwen__Qwen2.5-0.5B-Instruct'): string
{
    $dir = sys_get_temp_dir().'/lm-eval-'.uniqid();

    File::ensureDirectoryExists("$dir/$model");

    $results = [
        'arc_easy' => ['alias' => 'arc_easy', 'acc,none' => 0.6, 'acc_norm,none' => $scores['arc_easy']],
        'hellaswag' => ['alias' => 'hellaswag', 'acc,none' => 0.4, 'acc_norm,none' => $scores['hellaswag']],
        'ifeval' => ['alias' => 'ifeval', 'prompt_level_strict_acc,none' => $scores['ifeval'], 'inst_level_strict_acc,none' => 0.5],
        'gsm8k' => ['alias' => 'gsm8k', 'exact_match,strict-match' => $scores['gsm8k'], 'exact_match,flexible-extract' => 0.3],
    ];

    File::put("$dir/$model/results_2026-11-11T08-00-00.000000.json", json_encode(['results' => $results, 'config' => ['model' => 'hf']]));

    return $dir;
}

it('passes when nothing fell by more than the limit', function () {
    $base = lmEvalRun(['arc_easy' => 0.70, 'hellaswag' => 0.50, 'ifeval' => 0.30, 'gsm8k' => 0.20]);
    $tuned = lmEvalRun(['arc_easy' => 0.69, 'hellaswag' => 0.51, 'ifeval' => 0.29, 'gsm8k' => 0.205]);

    [$code, $out] = artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => $tuned]);

    expect($code)->toBe(0)->and($out)->toContain('No score fell by more than 2.0 points')->toContain('arc_easy')->toContain('-1.0');
});

it('trips on the task that lost more than the limit and says which', function () {
    $base = lmEvalRun(['arc_easy' => 0.70, 'hellaswag' => 0.50, 'ifeval' => 0.30, 'gsm8k' => 0.20]);
    $tuned = lmEvalRun(['arc_easy' => 0.70, 'hellaswag' => 0.50, 'ifeval' => 0.20, 'gsm8k' => 0.20]);

    [$code, $out] = artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => $tuned]);

    expect($code)->toBe(1)->and($out)->toContain('TRIPPED')->toContain('1 task(s) fell by more than 2.0 points');

    expect(artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => $tuned, '--max-drop' => 12])[0])->toBe(0);
});

it('treats a gain as no drop', function () {
    $base = lmEvalRun(['arc_easy' => 0.50, 'hellaswag' => 0.50, 'ifeval' => 0.30, 'gsm8k' => 0.20]);
    $tuned = lmEvalRun(['arc_easy' => 0.90, 'hellaswag' => 0.50, 'ifeval' => 0.30, 'gsm8k' => 0.20]);

    expect(artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => $tuned])[0])->toBe(0);
});

it('takes the newest results file and accepts a path to the file itself', function () {
    $base = lmEvalRun(['arc_easy' => 0.70, 'hellaswag' => 0.50, 'ifeval' => 0.30, 'gsm8k' => 0.20]);

    $old = $base.'/Qwen__Qwen2.5-0.5B-Instruct/results_2026-11-10T08-00-00.000000.json';

    File::put($old, json_encode(['results' => ['arc_easy' => ['acc_norm,none' => 0.10]]]));
    touch($old, time() - 3600);

    $file = $base.'/Qwen__Qwen2.5-0.5B-Instruct/results_2026-11-11T08-00-00.000000.json';

    expect(artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => $file])[0])->toBe(0);
});

it('says so when a run is missing, empty or covers different tasks', function () {
    $base = lmEvalRun(['arc_easy' => 0.70, 'hellaswag' => 0.50, 'ifeval' => 0.30, 'gsm8k' => 0.20]);

    expect(artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => '/nonexistent']))->toMatchArray([1, "No results_*.json under /nonexistent: run lm_eval with --output_path first.\n"]);

    $partial = sys_get_temp_dir().'/lm-partial-'.uniqid();

    File::ensureDirectoryExists($partial);
    File::put("$partial/results_x.json", json_encode(['results' => ['arc_easy' => ['acc_norm,none' => 0.7]]]));

    [$code, $out] = artisanOutput('eval:tripwire', ['base' => $base, 'candidate' => $partial]);

    expect($code)->toBe(1)->and($out)->toContain('The candidate has no hellaswag score');

    File::put("$partial/results_x.json", json_encode(['results' => ['mmlu' => ['acc,none' => 0.7]]]));

    expect(artisanOutput('eval:tripwire', ['base' => $partial, 'candidate' => $base])[1])->toContain('has none of the tripwire tasks');
});

it('reads a results file as lm_eval 0.4.13 wrote it (arc_easy and hellaswag on Qwen2.5-0.5B-Instruct, 40 samples each)', function () {
    $file = base_path('tests/Fixtures/lm-eval-results.json');

    [$code, $out] = artisanOutput('eval:tripwire', ['base' => $file, 'candidate' => $file]);

    expect($code)->toBe(0)->and($out)->toContain('arc_easy')->toContain('60.0')->toContain('hellaswag')->toContain('42.5');
});
