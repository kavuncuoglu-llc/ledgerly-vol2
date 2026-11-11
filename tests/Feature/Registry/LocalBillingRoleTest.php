<?php

use App\Ai\AiManager;
use App\Ai\Cost\PriceMap;
use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Prompts\SupportPrompt;

// Chapter 42, Topic 8 and Chapter 44, Figure 44-5: the role that serves the billing fine-tune from your own hardware.
it('is a pinned role with its provenance and the fine-tuned prompt version, and sends nothing until a server is named', function () {
    $role = config('ai.providers.local-billing-ft');

    expect($role['pinned'])->toBeTrue()
        ->and($role['fine_tuned']['prompt_version'])->toBe(FineTunedSupportPrompt::VERSION)
        ->and($role['fine_tuned']['base'])->toBe('meta-llama/Llama-3.1-8B-Instruct')
        ->and($role['privacy'])->toMatchArray(['redact' => false, 'trains_on_api_data' => false, 'dpa_signed' => true])
        ->and(app(AiManager::class)->configured('local-billing-ft'))->toBeFalse()
        ->and(config('ai.features')['support.reply']['allowed_providers'])->toContain('local-billing-ft');

    config(['ai.providers.local-billing-ft.base_url' => 'http://localhost:11434/v1']);

    expect(app(AiManager::class)->configured('local-billing-ft'))->toBeTrue();
});

it('is sent the short system message and the base role the long one', function () {
    config(['ai.security.canary' => '']);

    $messages = [['role' => 'system', 'content' => SupportPrompt::base()], ['role' => 'user', 'content' => 'q']];

    expect(SupportPrompt::boundTo($messages, 'local-billing-ft')[0]['content'])->toBe(FineTunedSupportPrompt::system())
        ->and(SupportPrompt::boundTo($messages, 'local')[0]['content'])->toBe(SupportPrompt::base())
        ->and(SupportPrompt::versionFor('local-billing-ft'))->toBe(SupportPrompt::FINE_TUNED_VERSION);
});

it('costs nothing per token on your own hardware, so an unpriced model is not the reason it is free', function () {
    expect(app(PriceMap::class)->find('local-billing-ft', 'ledgerly-billing-ft'))->toMatchArray(['input' => 0.0, 'output' => 0.0]);
});
