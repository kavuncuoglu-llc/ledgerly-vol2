<?php

use App\Ai\Timings\ModelTimings;

function figure310Response(): array
{
    return [
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => 'A late fee is a charge added when an invoice is paid after its due date.'],
        'done' => true,
        'done_reason' => 'stop',
        'total_duration' => 2_840_000_000,
        'load_duration' => 31_200_000,
        'prompt_eval_count' => 38,
        'prompt_eval_duration' => 412_000_000,
        'eval_count' => 19,
        'eval_duration' => 1_390_000_000,
    ];
}

it('reads Ollama native timing fields into prefill and decode rates', function () {
    $timings = ModelTimings::fromOllama(figure310Response());

    expect($timings->measured)->toBeTrue()
        ->and(round($timings->prefillRate(), 1))->toBe(92.2)
        ->and(round($timings->decodeRate(), 1))->toBe(13.7)
        ->and($timings->totalMs())->toBe(2840);
});

it('reports the same lines ollama run --verbose prints', function () {
    $report = ModelTimings::fromOllama(figure310Response())->report();

    expect($report)->toBe([
        'total duration' => '2.84s',
        'load duration' => '31.2ms',
        'prompt eval count' => '38 token(s)',
        'prompt eval duration' => '412ms',
        'prompt eval rate' => '92.2 tokens/s',
        'eval count' => '19 token(s)',
        'eval duration' => '1.39s',
        'eval rate' => '13.7 tokens/s',
    ]);
});

it('formats durations the way a stopwatch reads', function (int $nanoseconds, string $expected) {
    expect(ModelTimings::duration($nanoseconds))->toBe($expected);
})->with([
    'sub-millisecond' => [500_000, '0.5ms'],
    'whole milliseconds' => [412_000_000, '412ms'],
    'fractional milliseconds' => [31_200_000, '31.2ms'],
    'seconds' => [2_840_000_000, '2.84s'],
    'ten seconds and over' => [17_100_000_000, '17.1s'],
]);

it('shows prefill time growing with the prompt while decode holds steady', function () {
    $short = ModelTimings::fromOllama(figure310Response());
    $long = ModelTimings::fromOllama([
        ...figure310Response(),
        'prompt_eval_count' => 1562,
        'prompt_eval_duration' => 17_100_000_000,
        'eval_count' => 31,
        'eval_duration' => 2_370_000_000,
    ]);

    expect(round($long->prefillRate(), 1))->toBe(91.3)
        ->and(round($long->decodeRate(), 1))->toBe(13.1)
        ->and($long->promptEvalNs)->toBeGreaterThan($short->promptEvalNs * 30);
});

it('does not divide by zero when Ollama reports no prompt evaluation', function () {
    $timings = ModelTimings::fromOllama([...figure310Response(), 'prompt_eval_count' => 0, 'prompt_eval_duration' => 0]);

    expect($timings->prefillRate())->toBe(0.0)
        ->and($timings->report()['prompt eval rate'])->toBe('n/a');
});

it('only has the wall clock and usage counts for the OpenAI-compatible shape', function () {
    $timings = ModelTimings::fromUsage(['usage' => ['prompt_tokens' => 38, 'completion_tokens' => 19]], 2_000_000_000);

    expect($timings->measured)->toBeFalse()
        ->and($timings->promptTokens)->toBe(38)
        ->and($timings->completionTokens)->toBe(19)
        ->and($timings->prefillRate())->toBe(0.0)
        ->and(round($timings->overallRate(), 1))->toBe(9.5)
        ->and($timings->report())->toHaveKeys(['total duration', 'overall rate']);
});
