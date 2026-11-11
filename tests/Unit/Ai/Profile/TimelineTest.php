<?php

use App\Ai\Profile\MemorySample;
use App\Ai\Profile\PromptTrace;
use App\Ai\Profile\SampleParser;
use App\Ai\Profile\Timeline;

function tenTokensASecond(float $first, int $tokens, float $sent, int $promptTokens): PromptTrace
{
    $times = array_map(fn (int $i) => $first + $i / 10, range(0, $tokens - 1));

    return new PromptTrace('p', $sent, $first, end($times), $times, $promptTokens, $tokens);
}

it('lines load, prefill and decode up one row a second, the way Figure 21-9 does', function () {
    $trace = tenTokensASecond(first: 1004.0, tokens: 30, sent: 1002.0, promptTokens: 1118);

    $samples = [
        new MemorySample(1000, 3.1, 0.0),
        new MemorySample(1001, 11.4, 8.2),
        new MemorySample(1002, 41.9, 38.6),
        new MemorySample(1003, 43.0, 39.7),
        new MemorySample(1004, 43.0, 39.7),
        new MemorySample(1005, 43.1, 39.8),
        new MemorySample(1006, 43.2, 39.9),
    ];

    $profile = Timeline::build(1000.0, 1002.0, [$trace], $samples);

    expect(array_map(fn ($row) => $row->phase, $profile->rows))->toBe(['load', 'load', 'prefill', 'prefill', 'decode', 'decode', 'decode'])
        ->and($profile->rows[0]->residentGb)->toBe(3.1)
        ->and($profile->rows[2]->residentGb)->toBe(41.9)           // the newest sample at or before the start of that second
        ->and($profile->rows[2]->prefillRate)->toBe(559.0)         // 1,118 prompt tokens in the 2 s before the first token
        ->and($profile->rows[2]->contextTokens)->toBe(1118)
        ->and($profile->rows[4]->decodeRate)->toBe(10.0)
        ->and($profile->rows[4]->contextTokens)->toBe(1128)
        ->and($profile->rows[6]->contextTokens)->toBe(1118 + 30)
        ->and($profile->loadToFirstToken)->toBe(4.0)
        ->and($profile->prefillRate)->toBe(559.0)
        ->and(round($profile->decodeRate, 1))->toBe(10.0)           // 29 intervals in 2.9 s
        ->and($profile->peakGb)->toBe(43.2);
});

it('says nothing it cannot know: no samples, no usage', function () {
    $trace = new PromptTrace('p', 10.0, 11.0, 12.0, [11.0, 11.5, 12.0], 0, 0);

    $profile = Timeline::build(10.0, 10.0, [$trace], []);

    expect($profile->peakGb)->toBeNull()
        ->and($profile->rows[0]->residentGb)->toBeNull()
        ->and($profile->prefillRate)->toBeNull()
        ->and(round($profile->decodeRate, 1))->toBe(2.0);          // 3 chunks, so 2 intervals in 1 s
});

it('reads the blocks the sampling loop prints on a Mac', function () {
    $output = <<<'TXT'
@@sample 1000
@@ps
  3250000 /Applications/Ollama.app/Contents/Resources/ollama
@@gpu
"In use system memory"=0
@@sample 1001
@@ps
 45000000 /usr/local/bin/ollama
   100000 /usr/local/bin/ollama-helper
@@gpu
"In use system memory"=44023414784
TXT;

    $samples = SampleParser::parse($output, 'ioreg', ['ollama', 'llama-server']);

    expect($samples)->toHaveCount(2)
        ->and($samples[0]->at)->toBe(1000.0)
        ->and(round($samples[0]->residentGb, 2))->toBe(3.1)
        ->and(round($samples[1]->residentGb, 1))->toBe(43.0)
        ->and(round($samples[1]->gpuGb, 1))->toBe(41.0);
});

it('reads nvidia-smi MiB and amdgpu sysfs bytes, and a block with no server process', function () {
    $nvidia = SampleParser::parse("@@sample 5\n@@ps\n 2097152 llama-server\n@@gpu\n10240\n2048", 'nvidia', ['llama-server']);
    $amd = SampleParser::parse("@@sample 5\n@@ps\n@@gpu\n".(30 * 1073741824).' '.(10 * 1073741824), 'sysfs', ['ollama']);

    expect(round($nvidia[0]->residentGb, 1))->toBe(2.0)
        ->and($nvidia[0]->gpuGb)->toBe(12.0)
        ->and($amd[0]->residentGb)->toBeNull()
        ->and($amd[0]->gpuGb)->toBe(40.0);
});
