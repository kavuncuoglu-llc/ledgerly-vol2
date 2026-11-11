<?php

use App\Ai\Experiments\Candidate;
use App\Ai\Prompts\FineTunedSupportPrompt;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Registry\Aliases;
use App\Ai\Routing\RoutePlan;
use App\Ai\Routing\RouteTable;
use App\Ai\Schemas\Category;
use App\Facades\Ai;
use App\Facades\Registry;
use App\Models\ExperimentSetting;
use Tests\Support\RegistryWorld;

// Chapter 44, Topic 5 and Figure 44-5: a residency tenant's route has in-region roles only and ends in a person; a canary customer is served by the candidate where the route names the role it replaces; the role's model comes from the registry alias.
beforeEach(function () {
    RegistryWorld::boot();

    config([
        'ai.security.canary' => '',
        'ai.cascade.enabled' => false,
        'ai.providers.local-billing-ft.model' => 'ledgerly-billing-ft-v1',
        'ai.providers.mid-in-region.base_url' => 'http://mid-eu.test/v1',
        'ai.providers.mid-in-region.model' => 'llama3.1:70b',
        'ai.providers.mid-in-region.privacy.region' => 'eu',
        'ai.providers.local-billing-ft.privacy.region' => 'eu',
        'ai.providers.local-70b.base_url' => 'http://local-70b.test/v1',
        'ai.providers.local-70b.model' => 'llama3.1:70b',
        'ai.providers.frontier.base_url' => 'https://api.example.com/v1',
        'ai.providers.frontier.model' => 'illustrative-frontier',
        'ai.providers.frontier.api_key' => 'key',
        'ai.providers.frontier.privacy.dpa_signed' => true,
        'ai.providers.frontier.privacy.region' => 'us',
    ]);

    config(['ai-routes' => [
        'billing' => [
            'default' => ['start' => 'local-billing-ft', 'chain' => ['local-billing-ft', 'mid', 'frontier']],
            'residency' => ['start' => 'local-billing-ft', 'chain' => ['local-billing-ft', 'mid-in-region'], 'then' => 'person'],
        ],
    ]]);
});

afterEach(function () {
    RegistryWorld::clean();
});

it('gives a tenant without a residency the default billing chain', function () {
    $plan = app(RouteTable::class)->for(Category::Billing);

    expect($plan->chain)->toBe(['local-billing-ft', 'local-70b', 'frontier'])->and($plan->residency)->toBeNull();
});

it('gives a residency tenant the in-region chain and never a hosted role', function () {
    $plan = app(RouteTable::class)->for(Category::Billing, residency: 'eu');

    expect($plan->chain)->toBe(['local-billing-ft', 'mid-in-region'])->and($plan->residency)->toBe('eu');
});

it('refuses a role whose recorded region is not the tenant\'s, even when the route names it', function () {
    config(['ai-routes.billing.residency.chain' => ['local-billing-ft', 'frontier', 'mid-in-region']]);

    expect(app(RouteTable::class)->for(Category::Billing, residency: 'eu')->chain)->toBe(['local-billing-ft', 'mid-in-region']);

    config(['ai.providers.mid-in-region.privacy.region' => 'us']);

    expect(app(RouteTable::class)->for(Category::Billing, residency: 'eu')->chain)->toBe(['local-billing-ft']);
});

it('filters a category without a residency route by region too, falling to what is on the tenant\'s own hardware', function () {
    config(['ai-routes' => ['howto' => ['default' => ['start' => 'local', 'chain' => ['local', 'mid', 'frontier']]]]]);

    $plan = app(RouteTable::class)->for(Category::HowTo, residency: 'eu');

    expect($plan->chain)->toBe(['local', 'local-70b']);
});

it('hands a residency tenant to a person when no role in the route is in region', function () {
    config(['ai.providers.local-billing-ft.privacy.region' => 'us', 'ai.providers.mid-in-region.privacy.region' => 'us']);

    $plan = app(RouteTable::class)->for(Category::Billing, residency: 'eu');

    expect($plan->chain)->toBe([])->and($plan->handsToAPerson())->toBeTrue();
});

it('swaps the role a canary replaces and leaves the rest of the chain alone', function () {
    $plan = new RoutePlan('billing', ['local-billing-ft', 'mid', 'frontier'], true, false, null, null);

    $swapped = $plan->substituting('local-billing-ft', 'local-billing-ft@canary');

    expect($swapped->chain)->toBe(['local-billing-ft@canary', 'mid', 'frontier'])->and($plan->chain[0])->toBe('local-billing-ft');
});

it('reads a candidate from the registry: the role at the canary alias, with the fine-tuned prompt version', function () {
    RegistryWorld::entry('billing', 2, 'candidate');

    Registry::stage('billing', 2, 'shadow', 'maya');

    $candidate = Candidate::from('registry:local-billing-ft@shadow');

    expect($candidate->provider)->toBe('local-billing-ft@shadow')
        ->and($candidate->replaces)->toBe('local-billing-ft')
        ->and($candidate->name)->toBe('billing-v2')
        ->and($candidate->version)->toBe(FineTunedSupportPrompt::VERSION)
        ->and($candidate->system)->toBeNull()
        ->and(fn () => Candidate::from('registry:local-billing-ft@canary'))->toThrow(InvalidArgumentException::class, 'no version of that role is in a canary')
        ->and(fn () => Candidate::from('registry:local-billing-ft@production'))->toThrow(InvalidArgumentException::class, 'registry:<role>@canary');
});

it('names the fine-tuned role\'s model from the registry alias and keeps the book\'s name when there is none', function () {
    expect(Aliases::resolve('billing@production', 'ledgerly-billing-ft'))->toBe('ledgerly-billing-ft');

    RegistryWorld::entry('billing', 1, 'production');
    Registry::publish();

    expect(Aliases::resolve('billing@production', 'ledgerly-billing-ft'))->toBe('ledgerly-billing-ft-v1');
});

it('serves a canary customer by the candidate, and the others by production', function () {
    [$user] = customerWithInvoice('INV-4471');

    RegistryWorld::entry('billing', 1, 'production');
    RegistryWorld::entry('billing', 2, 'shadow');
    Registry::publish();
    Registry::stage('billing', 2, 'canary', 'maya', percent: 100);

    $features = config('ai.features');

    config(['ai.features' => array_replace($features, ['support.reply' => ['provider' => 'local-billing-ft'] + $features['support.reply']])]);

    Ai::fake()->for('support.reply')->respondWith('Open Settings, then Plan.');

    runAnswerQuestion('How do I change my plan?', as: $user);

    Ai::assertSent(fn ($request, string $provider) => $provider === 'local-billing-ft@canary'
        && str_starts_with($request->messages[0]['content'], FineTunedSupportPrompt::system())
        && $request->meta['prompt_version'] === SupportPrompt::FINE_TUNED_VERSION);

    ExperimentSetting::query()->where('name', 'registry-billing')->update(['percent' => 0]);

    Ai::fake()->for('support.reply')->respondWith('Open Settings, then Plan.');

    runAnswerQuestion('How do I change my plan today?', as: $user);

    Ai::assertSent(fn ($request, string $provider) => $provider === 'local-billing-ft');
});

it('keeps a residency tenant out of the canary', function () {
    [$user] = customerWithInvoice('INV-4471');

    $user->tenant->update(['data_residency' => 'eu']);

    RegistryWorld::entry('billing', 1, 'production');
    RegistryWorld::entry('billing', 2, 'shadow');
    Registry::stage('billing', 2, 'canary', 'maya', percent: 100);

    $features = config('ai.features');

    config(['ai.features' => array_replace($features, ['support.reply' => ['provider' => 'local-billing-ft'] + $features['support.reply']])]);

    Ai::fake()->for('support.reply')->respondWith('Open Settings, then Plan.');

    runAnswerQuestion('How do I change my plan?', as: $user);

    Ai::assertNothingSentTo('local-billing-ft@canary');
});

it('answers a residency tenant with the escape hatch and no model call when no role is in their region', function () {
    [$user] = customerWithInvoice('INV-4471');

    $user->tenant->update(['data_residency' => 'eu']);

    config([
        'ai.cascade.enabled' => true,
        'ai.providers.local-billing-ft.privacy.region' => 'us',
        'ai.providers.mid-in-region.privacy.region' => 'us',
        'ai-routes' => ['default' => ['default' => ['start' => 'frontier', 'chain' => ['frontier']]]],
    ]);

    Ai::fake()->for('support.reply')->respondWith('This should never be sent.');

    $reply = runAnswerQuestion('How do I change my plan?', as: $user);

    expect($reply->content)->toBe(SupportPrompt::escapeHatch());

    Ai::assertNothingSentTo('frontier');
});
