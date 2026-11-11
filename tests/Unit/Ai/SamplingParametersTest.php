<?php

use App\Ai\Sampling\SamplingParameters;

it('ships the four Figure 5-10 profiles', function () {
    expect(config('ai.profiles'))->toBe([
        'deterministic' => ['temperature' => 0, 'top_p' => 1.0, 'seed' => 42],
        'grounded' => ['temperature' => 0.2, 'top_p' => 0.9],
        'assistant' => ['temperature' => 0.7, 'top_p' => 0.9],
        'creative' => ['temperature' => 1.0, 'top_p' => 0.95],
    ]);
});

it('starts from a profile and lets explicit flags override it', function () {
    $parameters = SamplingParameters::fromProfile('assistant')->merge(['temperature' => 0.0, 'max_tokens' => 40]);

    expect($parameters->all())->toBe(['temperature' => 0.0, 'top_p' => 0.9, 'max_tokens' => 40]);
});

it('keeps a temperature of zero instead of treating it as unset', function () {
    $parameters = (new SamplingParameters)->merge(['temperature' => 0.0, 'top_p' => null, 'stop' => []]);

    expect($parameters->all())->toBe(['temperature' => 0.0]);
});

it('refuses a profile that is not in config', function () {
    SamplingParameters::fromProfile('chaotic');
})->throws(InvalidArgumentException::class, "No sampling profile 'chaotic'");

it('sends only what /v1/ understands in the OpenAI body', function () {
    $parameters = new SamplingParameters([
        'temperature' => 0.7,
        'top_k' => 40,
        'min_p' => 0.05,
        'repeat_penalty' => 1.1,
        'max_tokens' => 5,
        'stop' => ["\n"],
        'seed' => 42,
    ]);

    expect($parameters->openAiBody())->toBe(['temperature' => 0.7, 'max_tokens' => 5, 'stop' => ["\n"], 'seed' => 42]);
});

it('renames max_tokens for the native options and keeps the native-only knobs', function () {
    $parameters = new SamplingParameters(['temperature' => 0, 'top_k' => 40, 'max_tokens' => 5, 'repeat_penalty' => 1.1]);

    expect($parameters->ollamaOptions())->toBe(['temperature' => 0, 'top_k' => 40, 'num_predict' => 5, 'repeat_penalty' => 1.1]);
});

it('knows when /v1/ cannot carry a knob', function () {
    expect((new SamplingParameters(['temperature' => 0]))->needsNativeApi())->toBeFalse()
        ->and((new SamplingParameters(['min_p' => 0.05]))->needsNativeApi())->toBeTrue()
        ->and((new SamplingParameters(['min_p' => 0.05, 'top_k' => 20, 'top_p' => 0.9]))->nativeOnlyKeys())->toBe(['min_p', 'top_k']);
});
