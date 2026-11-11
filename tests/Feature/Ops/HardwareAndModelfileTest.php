<?php

it('has a Modelfile for the 70B with the same engine defaults as the 8B budget', function () {
    $modelfile = parseModelfile(base_path('ops/ollama/Modelfile.ledgerly-support-70b'));

    expect($modelfile['FROM'])->toBe(['llama3.1:70b-instruct-q4_K_M'])
        ->and($modelfile['PARAMETER']['num_ctx'])->toBe(['8192'])
        ->and((int) $modelfile['PARAMETER']['num_ctx'][0])->toBe((int) config('ai.budget.window'))
        ->and($modelfile['PARAMETER']['num_predict'])->toBe(['400'])
        ->and($modelfile['PARAMETER']['stop'])->toBe(['<|eot_id|>'])
        ->and($modelfile['SYSTEM'])->toBe([])                       // the prompt lives in SupportPrompt
        ->and($modelfile['LICENSE'][0])->toContain('Llama 3.1 Community License');
});

it('points the mid tier at a 70B that is unset until the reader names it', function () {
    expect(config('ai.tiers.mid'))->toBe('local-70b')
        ->and(config('ai.providers.local-70b.driver'))->toBe('openai-compatible')
        ->and(config('ai.providers.local-70b.timeout'))->toBe(300)
        ->and(config('ai.providers.local-70b.model'))->toBeEmpty();
});

it('ships the hardware notes the chapter names', function () {
    expect(file_get_contents(base_path('ops/hardware/macos.md')))->toContain('iogpu.wired_limit_mb')
        ->and(file_get_contents(base_path('ops/hardware/linux-apu.md')))->toContain('gttsize')->toContain('HSA_OVERRIDE_GFX_VERSION');
});
