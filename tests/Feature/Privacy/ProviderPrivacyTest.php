<?php

use App\Facades\Ai;
use Illuminate\Support\Facades\Log;

// Chapter 34, Topic 2: the five questions answered once per provider in config, and the router refusing what the answers do not allow.
beforeEach(function () {
    config([
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'frontier-example',
    ]);
})->group('privacy');

it('records the five answers beside every provider that is not a local server, with a date and a source', function () {
    $privacy = config('ai.providers.frontier.privacy');

    expect($privacy)->toHaveKeys(['trains_on_api_data', 'retention_days', 'zdr', 'region', 'dpa_signed', 'checked_on', 'source', 'redact'])
        ->and($privacy['trains_on_api_data'])->toBeFalse()
        ->and($privacy['redact'])->toBeTrue();
});

it('says for every configured provider whether it redacts, and redacts by default where nothing is said', function () {
    $redacting = collect(config('ai.providers'))
        ->filter(fn ($config) => is_array($config) && ($config['privacy']['redact'] ?? true))
        ->keys()
        ->all();

    expect($redacting)->toBe(['frontier', 'frontier-billing-ft']);       // the hosted fine-tune is a hosted role too (Chapter 41)
});

it('refuses to escalate to a provider whose recorded answers the feature requires and lacks', function () {
    config(['ai.providers.frontier.privacy.dpa_signed' => false]);

    expect(Ai::escalationFor('support.reply'))->toBeNull();

    config(['ai.providers.frontier.privacy.dpa_signed' => true]);

    expect(Ai::escalationFor('support.reply'))->toBe('frontier');
});

it('refuses a provider that is not in the feature\'s allowed list, whatever its answers are', function () {
    config(['ai.providers.frontier.privacy.dpa_signed' => true]);

    expect(Ai::mayReceive('invoice.extract', 'frontier'))->toBeFalse()
        ->and(Ai::mayReceive('invoice.extract', 'vision'))->toBeTrue()
        ->and(Ai::mayReceive('support.reply', 'frontier'))->toBeTrue()
        ->and(Ai::mayReceive('ticket.classify', 'frontier'))->toBeTrue();      // no list, no requirement: the feature says nothing
});

it('sends a feature that was configured onto a provider it may not use to the local role, and says so', function () {
    Log::spy();

    Ai::fake();

    config(['ai.features.invoice.extract.provider' => 'frontier']);

    config(['ai.features' => [...config('ai.features'), 'invoice.extract' => ['provider' => 'frontier', 'allowed_providers' => ['local', 'vision']]]]);

    Ai::fake()->for('invoice.extract')->respondWith('{}');

    expect(Ai::for('invoice.extract')->name())->toBe('local');

    Log::shouldHaveReceived('warning')->with('ai.provider_not_allowed', ['feature' => 'invoice.extract', 'provider' => 'frontier', 'used' => 'local'])->once();
});

it('keeps scanned documents on the machine: the invoice feature may use local models only', function () {
    expect(config('ai.features')['invoice.extract']['allowed_providers'])->toBe(['local', 'vision']);
});
