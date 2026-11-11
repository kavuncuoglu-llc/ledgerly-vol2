<?php

use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Prompts\SupportPrompt;
use App\Facades\Ai;

// Chapter 41, Topic 5: through the real answer path, the fine-tuned role is sent the short system message and the request says which prompt version it answered with; the base role keeps the long one.
beforeEach(function () {
    [$this->user] = customerWithInvoice('INV-4471');

    config([
        'ai.security.canary' => '',
        'ai.cascade.enabled' => false,
        'ai.providers.frontier-billing-ft.privacy.dpa_signed' => true,
        'ai.providers.frontier-billing-ft.base_url' => 'https://api.example.com/v1',
        'ai.providers.frontier-billing-ft.model' => 'ft:fake-billing:abc',
    ]);
});

function pointSupportAt(string $provider): void
{
    $features = config('ai.features');

    config(['ai.features' => array_replace($features, ['support.reply' => ['provider' => $provider] + $features['support.reply']])]);
}

it('sends the fine-tuned role the short system message', function () {
    pointSupportAt('frontier-billing-ft');

    Ai::fake()->for('support.reply')->respondWith('Open Settings, then Plan.');

    runAnswerQuestion('How do I change my plan?', as: $this->user);

    Ai::assertSent(function ($request, string $provider) {
        return $provider === 'frontier-billing-ft'
            && str_starts_with($request->messages[0]['content'], FineTunedSupportPrompt::system())
            && ! str_contains($request->messages[0]['content'], 'At most four sentences')
            && $request->meta['prompt_version'] === SupportPrompt::FINE_TUNED_VERSION;
    });
});

it('sends the base role the long one and records its version', function () {
    pointSupportAt('local');

    Ai::fake()->for('support.reply')->respondWith('Open Settings, then Plan.');

    runAnswerQuestion('How do I change my plan?', as: $this->user);

    Ai::assertSent(fn ($request, string $provider) => $provider === 'local'
        && str_contains($request->messages[0]['content'], 'At most four sentences')
        && $request->meta['prompt_version'] === SupportPrompt::VERSION);
});
